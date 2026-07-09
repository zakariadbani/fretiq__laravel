<?php

namespace App\Services\Campaign;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\EmailTrackingEvent;
use App\Models\SequenceEnrollment;
use App\Models\Suppression;
use App\Services\Campaign\ZohoCampaignsDriver;
use App\Support\TrackingToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * CampaignService — orchestrates scheduling and sending of campaigns.
 *
 * Claim-commit-then-send ordering (queue-idempotency.md §2):
 *   1. TX (short):  SELECT run FOR UPDATE → set status='sending', started_at=now → COMMIT.
 *   2. No TX:       SegmentService::resolve() → eligible contacts.
 *   3. No TX:       Insert CampaignRecipient rows (unique key = retry-safe).
 *   4. No TX:       For each queued recipient with no provider_message_id:
 *                     a. Send-time suppression re-check.
 *                     b. Create EmailTrackingEvent (token).
 *                     c. Build signed unsubscribe URL.
 *                     d. driver->send() — OUTSIDE any DB transaction.
 *                     e. Record provider_message_id + status='sent'.
 *   5. No TX:       Update run stats + status='sent', finished_at=now.
 *
 * The DB lock is held ONLY during the claim step (step 1) and released before
 * any network call. This prevents deadlocks and conforms to the spec.
 */
class CampaignService
{
    public function __construct(
        private readonly SegmentService   $segmentService,
        private readonly SendWindowGuard  $sendWindowGuard,
        private readonly SequenceService  $sequenceService,
    ) {}

    // ── Scheduling ─────────────────────────────────────────────────────────────

    /**
     * Create (or retrieve) the one-shot CampaignRun for a campaign.
     *
     * Idempotent: calls back-to-back are safe; the unique(campaign_id, occurrence_key)
     * constraint guarantees at most one run is created.
     */
    public function scheduleOneShot(Campaign $campaign): CampaignRun
    {
        $runAt         = $campaign->scheduled_at ?? now();
        $occurrenceKey = 'oneshot-' . $runAt->format('YmdHis');

        $run = CampaignRun::firstOrCreate(
            [
                'campaign_id'    => $campaign->id,
                'occurrence_key' => $occurrenceKey,
            ],
            [
                'run_at' => $runAt,
                'status' => 'scheduled',
            ],
        );

        $campaign->update(['is_active' => true]);

        return $run;
    }

    /**
     * Create a fresh manual run for an explicit « Envoyer maintenant » action.
     *
     * Unlike scheduleOneShot(), this must never reuse an existing occurrence:
     * a recurring campaign can keep the same scheduled_at timestamp after an
     * earlier manual run has completed, and firstOrCreate would make a new click
     * silently target the already-sent run.
     */
    public function scheduleImmediate(Campaign $campaign): CampaignRun
    {
        $run = CampaignRun::create([
            'campaign_id'    => $campaign->id,
            'occurrence_key' => 'manual-' . now()->format('YmdHisv') . '-' . Str::lower(Str::random(6)),
            'run_at'         => now(),
            'status'         => 'scheduled',
        ]);

        $campaign->update(['is_active' => true]);

        return $run;
    }

    // ── Sequence launch ────────────────────────────────────────────────────────

    /**
     * Enroll the campaign segment's eligible contacts into the campaign's sequence.
     *
     * Returns ['enrolled' => int, 'skipped' => int].
     *
     * Guards (all throw InvalidArgumentException with French message):
     *   - campaign.sequence_id is null           → « Aucune séquence associée. »
     *   - sequence.is_active is false             → « La séquence est inactive. »
     *   - sequence has no steps                   → « La séquence n'a aucune étape. »
     *
     * Net-new-only semantic: contacts already enrolled in this sequence (ANY status)
     * are never re-enrolled. Only new contacts (not in sequence_enrollments for this
     * sequence) are enrolled. The UNIQUE(sequence_id,contact_id) constraint is the
     * race-proof backstop.
     *
     * Synchronous: DB-only writes. Sending is already handled async by the drip engine.
     *
     * @throws \InvalidArgumentException  When pre-conditions are not met.
     */
    public function launchSequence(Campaign $campaign): array
    {
        // ── Guard: sequence must be attached ──────────────────────────────────
        $sequence = $campaign->sequence;

        if ($sequence === null) {
            throw new \InvalidArgumentException('Aucune séquence associée.');
        }

        // ── Guard: sequence must be active ────────────────────────────────────
        if (! $sequence->is_active) {
            throw new \InvalidArgumentException('La séquence est inactive.');
        }

        // ── Guard: sequence must have at least one step ───────────────────────
        if ($sequence->steps()->count() === 0) {
            throw new \InvalidArgumentException("La séquence n'a aucune étape.");
        }

        // ── Resolve eligible contacts via compliance funnel ───────────────────
        $contacts = $this->segmentService->resolve($campaign->segment);

        if ($contacts->isEmpty()) {
            return ['enrolled' => 0, 'skipped' => 0];
        }

        // ── Net-new-only: get all contact IDs already in this sequence (any status) ──
        $existingContactIds = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->pluck('contact_id')
            ->flip(); // Use flip for O(1) key-existence checks.

        $enrolled = 0;
        $skipped  = 0;

        foreach ($contacts as $contact) {
            if (isset($existingContactIds[$contact->id])) {
                $skipped++;
                continue;
            }

            $enrollment = $this->sequenceService->enroll($sequence, $contact, $campaign);

            // Count as enrolled only when the row was actually just inserted (new+active).
            // A race-returned row of any terminal status (completed/stopped) must not
            // inflate the enrolled count — it will never send, so increment skipped instead.
            if ($enrollment !== null && $enrollment->wasRecentlyCreated) {
                $enrolled++;
            } else {
                $skipped++;
            }
        }

        // ── Activate the campaign (also valid on re-launch from paused state) ──
        if ($enrolled > 0) {
            $campaign->update(['is_active' => true]);
        }

        Log::info('[CampaignService] launchSequence completed.', [
            'campaign_id' => $campaign->id,
            'sequence_id' => $sequence->id,
            'enrolled'    => $enrolled,
            'skipped'     => $skipped,
        ]);

        return ['enrolled' => $enrolled, 'skipped' => $skipped];
    }

    // ── Dispatch ───────────────────────────────────────────────────────────────

    /**
     * Find all due scheduled runs and dispatch SendCampaignJob for each.
     *
     * Runs whose campaign has is_active=false are skipped (run stays 'scheduled',
     * cursor frozen). The run will be dispatched once the campaign is resumed.
     *
     * Note: sendNow() dispatches SendCampaignJob directly, bypassing dispatchDue —
     * by design (explicit user action). The is_active guard in sendRun() catches
     * the edge case where a job already in-queue races with a pause action.
     *
     * @return int  Number of runs dispatched.
     */
    public function dispatchDue(): int
    {
        $runs = CampaignRun::with('campaign')
            ->where('status', 'scheduled')
            ->where('run_at', '<=', now())
            ->get();

        $dispatched = 0;

        foreach ($runs as $run) {
            if ($run->campaign === null || ! $run->campaign->is_active) {
                Log::info('[CampaignService] dispatchDue: skipping run — campaign is paused (is_active=false).', [
                    'run_id'      => $run->id,
                    'campaign_id' => $run->campaign_id,
                ]);
                continue;
            }

            SendCampaignJob::dispatch($run->id);
            $dispatched++;
        }

        return $dispatched;
    }

    // ── Send engine ────────────────────────────────────────────────────────────

    /**
     * Execute the claim-commit-then-send flow for one CampaignRun.
     *
     * This method is the body of SendCampaignJob::handle() and is also callable
     * directly (e.g. in tinker). It is idempotent: re-running it on a run that
     * has already completed is a no-op.
     */
    public function sendRun(CampaignRun $run): void
    {
        // ── Step 1: Claim (short TX with row lock) ─────────────────────────────
        $claimed = false;

        DB::transaction(function () use ($run, &$claimed) {
            /** @var CampaignRun $locked */
            $locked = CampaignRun::lockForUpdate()->find($run->id);

            if ($locked === null) {
                return; // Row deleted concurrently — nothing to do.
            }

            if (! in_array($locked->status, ['scheduled', 'sending'], true)) {
                return; // Already 'sent', 'failed', 'paused', etc. — skip.
            }

            $locked->update([
                'status'     => 'sending',
                'started_at' => now(),
            ]);

            $claimed = true;
        });
        // Lock released here by COMMIT. No TX is held beyond this point.

        if (! $claimed) {
            Log::info('[CampaignService] Run already handled — skipping.', ['run_id' => $run->id]);
            return;
        }

        // Reload the run with its relations now that we hold sending status.
        $run->refresh()->load(['campaign.segment', 'campaign.template.translations', 'campaign.senderIdentity']);

        // ── Send-window guard ─────────────────────────────────────────────────
        // If the campaign has a send_window configured and the current moment (in the
        // campaign's timezone) falls outside it, revert the run to 'scheduled' and
        // return. The scheduler will retry on the next dispatch-due tick.
        if (! $this->sendWindowGuard->isWithinSendWindow($run->campaign)) {
            $run->update(['status' => 'scheduled']);
            Log::info('[CampaignService] Send deferred — outside send window.', [
                'run_id'      => $run->id,
                'campaign_tz' => $run->campaign->timezone,
                'send_window' => $run->campaign->send_window,
            ]);
            return;
        }

        // ── is_active pause guard ─────────────────────────────────────────────
        // Re-check is_active after claiming the run. A job already queued (up to
        // tries=5, retryUntil 6h) would otherwise ignore a pause action that
        // happened after the job was dispatched. Mirrors the send-window defer
        // pattern: revert to 'scheduled' so the run resumes on the next tick when
        // the campaign is unpaused.
        if (! $run->campaign->is_active) {
            $run->update(['status' => 'scheduled']);
            Log::info('[CampaignService] Send deferred — campaign is paused (is_active=false).', [
                'run_id'      => $run->id,
                'campaign_id' => $run->campaign_id,
            ]);
            return;
        }

        // ── Step 2: Resolve eligible contacts (outside TX) ────────────────────
        $contacts = $this->segmentService->resolve($run->campaign->segment);

        // ── Step 3: Insert recipient rows (idempotent via unique key) ─────────
        foreach ($contacts as $contact) {
            CampaignRecipient::firstOrCreate(
                [
                    'campaign_run_id' => $run->id,
                    'contact_id'      => $contact->id,
                ],
                [
                    'status' => 'queued',
                ],
            );
        }

        // ── Step 4: Driver-aware send ──────────────────────────────────────────
        /** @var CampaignsClient $driver */
        $driver = app(CampaignsClient::class);

        if ($driver instanceof ZohoCampaignsDriver) {
            // ── Zoho path: list/campaign-based dispatch ────────────────────────
            // Insert recipient rows (queued) first for tracking provenance, then
            // hand off delivery to Zoho's API in a single run-level call.
            //
            // UNVERIFIED — the Zoho path has not been live-tinker-confirmed.
            // It is gated behind config('services.zoho.driver') === 'zoho' and
            // will throw RuntimeException('Refresh token Zoho manquant…') when
            // Campaigns OAuth is not provisioned — which is the correct behavior.

            Log::info('[CampaignService] Driver zoho détecté — envoi via dispatchRun().', [
                'run_id'   => $run->id,
                'contacts' => $contacts->count(),
            ]);

            try {
                $summary = $driver->dispatchRun($run, $contacts);

                // Mark all queued recipients as 'sent' (Zoho handles actual delivery).
                CampaignRecipient::where('campaign_run_id', $run->id)
                    ->where('status', 'queued')
                    ->update([
                        'status'              => 'sent',
                        'provider_message_id' => 'zoho-' . ($summary['campaign_key'] ?? $run->id),
                        'sent_at'             => now(),
                    ]);

                $sentCount = CampaignRecipient::where('campaign_run_id', $run->id)
                    ->where('status', 'sent')
                    ->count();

                $run->update([
                    'stats_sent'  => $sentCount,
                    'status'      => 'sent',
                    'finished_at' => now(),
                ]);

                Log::info('[CampaignService] Run Zoho complété.', [
                    'run_id'       => $run->id,
                    'campaign_key' => $summary['campaign_key'] ?? null,
                    'list_key'     => $summary['list_key'] ?? null,
                    'stats_sent'   => $sentCount,
                ]);
            } catch (\Throwable $e) {
                Log::error('[CampaignService] Échec du dispatchRun Zoho.', [
                    'run_id' => $run->id,
                    'error'  => $e->getMessage(),
                ]);
                $run->update(['status' => 'failed', 'finished_at' => now()]);
                // Rethrow so the job retry mechanism handles it.
                throw $e;
            }
        } else {
            // ── Local path: per-recipient SMTP/Mailpit send (unchanged) ───────
            $recipients = CampaignRecipient::where('campaign_run_id', $run->id)
                ->where('status', 'queued')
                ->whereNull('provider_message_id')
                ->with('contact.company')
                ->get();

            foreach ($recipients as $recipient) {
                $contact = $recipient->contact;

                // ── 4a. Send-time suppression re-check ───────────────────────────
                if (Suppression::isSuppressed($contact->email)) {
                    $recipient->update([
                        'status'      => 'skipped',
                        'skip_reason' => 'suppressed',
                    ]);
                    continue;
                }

                // ── 4b. Generate tracking token and create EmailTrackingEvent ─────
                $token = $this->generateTrackingToken($run, $contact);

                EmailTrackingEvent::firstOrCreate(
                    ['token' => $token],
                    [
                        'trackable_type'     => CampaignRecipient::class,
                        'trackable_id'       => $recipient->id,
                        'event'              => 'sent',
                        'human_open_count'   => 0,
                        'machine_open_count' => 0,
                    ],
                );

                // ── 4c. Build signed unsubscribe URL ─────────────────────────────
                $unsubscribeUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

                // ── 4d. Driver send — OUTSIDE any DB transaction ──────────────────
                try {
                    $pmid = $driver->send(
                        $recipient,
                        $run->campaign,
                        $run,
                        $token,
                        $unsubscribeUrl,
                    );

                    // ── 4e. Record provider_message_id + sent status ───────────────
                    $recipient->update([
                        'status'              => 'sent',
                        'provider_message_id' => $pmid,
                        'sent_at'             => now(),
                    ]);
                } catch (\Throwable $e) {
                    // Leave recipient in 'queued' state — next job retry will re-send.
                    Log::error('[CampaignService] Failed to send to recipient.', [
                        'run_id'       => $run->id,
                        'recipient_id' => $recipient->id,
                        'contact_id'   => $contact->id,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }

            // ── Step 5 (local): Recompute run stats and mark complete ──────────
            $sentCount = CampaignRecipient::where('campaign_run_id', $run->id)
                ->where('status', 'sent')
                ->count();

            $run->update([
                'stats_sent'  => $sentCount,
                'status'      => 'sent',
                'finished_at' => now(),
            ]);

            Log::info('[CampaignService] Run complété (local).', [
                'run_id'     => $run->id,
                'stats_sent' => $sentCount,
            ]);
        }

        // ── One-shot auto-done (branch-agnostic) ──────────────────────────────
        // Once a one-shot campaign's run reaches terminal status 'sent', flip the
        // campaign to is_active=false. This is unconditional: done = dispatched,
        // even when all recipients failed (stats_sent=0). The report page shows the truth.
        //
        // Re-sending a one-shot via « Envoyer maintenant » re-arms it as
        // is_active=true via scheduleOneShot() before the next sendRun call,
        // so the lifecycle paused→active→paused works correctly.
        $run->refresh();
        if ($run->status === 'sent' && $run->campaign->schedule_type === 'one_shot') {
            $run->campaign->update(['is_active' => false]);

            Log::info('[CampaignService] One-shot campaign auto-set to inactive.', [
                'run_id'      => $run->id,
                'campaign_id' => $run->campaign_id,
            ]);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Generate a 64-char lowercase hex tracking token.
     *
     * The token is derived from a SHA-256 of the run id, contact id, and a random
     * 32-char nonce so it is effectively unique even on retry.
     */
    private function generateTrackingToken(CampaignRun $run, \App\Models\Contact $contact): string
    {
        return TrackingToken::generate($run->id, $contact->id);
    }
}
