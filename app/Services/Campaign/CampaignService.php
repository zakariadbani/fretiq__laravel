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
use Illuminate\Support\Collection;
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


    /**
     * Authoritative send preflight used by preview, controller actions, scheduler, and jobs.
     *
     * @return array{ok: bool, count: int, contact_count: int, company_count: int, contacts: \Illuminate\Support\Collection, messages: array<int, string>}
     */
    public function dispatchPreflight(Campaign $campaign, ?CampaignRun $run = null, bool $allowEmptyAudience = false): array
    {
        $campaign->loadMissing(['segment', 'template', 'senderIdentity', 'sequence']);

        $messages = [];

        if ($campaign->segment === null) {
            $messages[] = 'Sélectionnez un segment avant de lancer la campagne.';
        }

        if ($campaign->senderIdentity === null) {
            $messages[] = 'Sélectionnez un expéditeur avant de lancer la campagne.';
        }

        if ($campaign->schedule_type === 'sequence') {
            if ($campaign->sequence === null) {
                $messages[] = 'Sélectionnez une séquence avant de lancer la campagne.';
            }
        } elseif ($campaign->template === null) {
            $messages[] = 'Sélectionnez un modèle email avant de lancer la campagne.';
        }

        $contacts = collect();
        if ($campaign->segment !== null) {
            try {
                $contacts = $campaign->schedule_type === 'paced' && $run !== null
                    ? CampaignRecipient::query()
                        ->where('campaign_run_id', $run->id)
                        ->with('contact.company')
                        ->get()
                        ->pluck('contact')
                        ->filter()
                        ->values()
                    : $this->segmentService->resolve($campaign->segment);

                if ($contacts->isEmpty() && ! $allowEmptyAudience) {
                    $messages[] = 'Aucun destinataire éligible après exclusions, suppressions et règles de conformité.';
                }
            } catch (\Throwable $e) {
                $messages[] = 'Impossible de vérifier l’audience finale : corrigez le segment avant de lancer la campagne.';
            }
        }

        $isPacedSequence = $campaign->schedule_type === 'sequence'
            && $campaign->sequence_enrollment_mode === 'paced';

        if ($campaign->schedule_type === 'paced' && $campaign->usesZohoDriver()) {
            $messages[] = 'L’envoi progressif est indisponible avec le pilote Zoho tant que l’envoi par lot n’a pas été vérifié.';
        } elseif ($campaign->usesZohoDriver()) {
            $listKey = trim((string) ($campaign->zoho_list_key ?: config('services.zoho.campaigns.list_key')));
            if (! $isPacedSequence && $listKey === '') {
                $messages[] = 'Préparation Zoho incomplète : ajoutez et vérifiez la liste Zoho dédiée avant de lancer l’envoi.';
            }

            if (trim((string) config('services.zoho.campaigns.topic_id')) === '') {
                $messages[] = 'Préparation Zoho incomplète : sujet (topic) Zoho non configuré.';
            }
        }

        return [
            'ok' => $messages === [],
            'count' => $contacts->count(),
            'contact_count' => $contacts->count(),
            'company_count' => $contacts->pluck('company_id')->filter()->unique()->count(),
            'contacts' => $contacts,
            'messages' => array_values(array_unique($messages)),
        ];
    }

    private function preflightMessage(array $preflight): string
    {
        return implode(' ', $preflight['messages'] ?: ['Campagne bloquée : vérification d’envoi incomplète.']);
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
        if ($campaign->sequence_enrollment_mode === 'paced') {
            throw new \InvalidArgumentException('Une séquence progressive doit être traitée par lots planifiés.');
        }

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

            $preflight = $this->dispatchPreflight($run->campaign, $run);
            if (! $preflight['ok']) {
                $run->update(['status' => 'failed', 'finished_at' => now()]);
                $this->finalizePacedFailure(
                    $run,
                    new \RuntimeException('Dispatch preflight failed.'),
                );
                Log::warning('[CampaignService] dispatchDue: run blocked by dispatch preflight.', [
                    'run_id' => $run->id,
                    'campaign_id' => $run->campaign_id,
                    'message' => $this->preflightMessage($preflight),
                ]);
                continue;
            }

            $claimed = CampaignRun::whereKey($run->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'sending', 'started_at' => now()]);

            if ($claimed !== 1) {
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
        // Dispatch preflight is repeated inside the job so queued/stale work fails closed.
        $preflight = $this->dispatchPreflight($run->campaign, $run);
        if (! $preflight['ok']) {
            $run->update(['status' => 'failed', 'stats_sent' => 0, 'finished_at' => now()]);
            $this->finalizePacedFailure(
                $run,
                new \RuntimeException('Dispatch preflight failed.'),
            );
            Log::warning('[CampaignService] Send blocked by dispatch preflight.', [
                'run_id' => $run->id,
                'campaign_id' => $run->campaign_id,
                'message' => $this->preflightMessage($preflight),
            ]);
            return;
        }

        $contacts = $preflight['contacts'];

        // ── Step 3: Insert recipient rows (idempotent via unique key) ─────────
        if ($run->campaign->schedule_type !== 'paced') {
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
        }

        // ── Step 4: Driver-aware send ──────────────────────────────────────────
        /** @var CampaignsClient $driver */
        $driver = app(CampaignsClient::class);

        if ($driver instanceof ZohoCampaignsDriver) {
            $this->sendViaZoho($run, $contacts);
        } else {
            $this->sendViaLocal($run);
        }

        // ── One-shot auto-done (branch-agnostic) ──────────────────────────────
        // A one-shot campaign auto-completes (is_active=false) only when its run
        // reaches terminal status 'sent'. A run where every recipient failed
        // (sendViaLocal marks it 'failed') is intentionally left is_active=true —
        // the campaign stays active so the operator can retry via
        // « Envoyer maintenant » without having to manually re-enable it first.
        //
        // Re-sending a one-shot via « Envoyer maintenant » re-arms it as
        // is_active=true via scheduleOneShot() before the next sendRun call,
        // so the lifecycle paused→active→paused works correctly on a 'sent' run.
        $run->refresh();
        if ($run->status === 'sent' && $run->campaign->schedule_type === 'one_shot') {
            $run->campaign->update(['is_active' => false]);

            Log::info('[CampaignService] One-shot campaign auto-set to inactive.', [
                'run_id'      => $run->id,
                'campaign_id' => $run->campaign_id,
            ]);
        }
    }

    /**
     * Zoho path: list/campaign-based dispatch.
     *
     * Insert recipient rows (queued) first for tracking provenance, then
     * hand off delivery to Zoho's API in a single run-level call.
     *
     * UNVERIFIED — the Zoho path has not been live-tinker-confirmed.
     * It is gated behind config('services.zoho.driver') === 'zoho' and
     * will throw RuntimeException('Refresh token Zoho manquant…') when
     * Campaigns OAuth is not provisioned — which is the correct behavior.
     *
     * Rethrows on failure — the job retry mechanism handles it.
     */
    private function sendViaZoho(CampaignRun $run, \Illuminate\Support\Collection $contacts): void
    {
        /** @var CampaignsClient $driver */
        $driver = app(CampaignsClient::class);

        Log::info('[CampaignService] Driver zoho détecté — envoi via dispatchRun().', [
            'run_id'   => $run->id,
            'contacts' => $contacts->count(),
        ]);

        try {
            $summary = $driver->dispatchRun($run, $contacts);

            $sentCount = 0;

            DB::transaction(function () use ($run, $summary, &$sentCount) {
                // Mark all queued recipients as 'sent' (Zoho handles actual delivery).
                // Zoho sends one campaign to the whole list — the provider id is per-RUN, not per-recipient,
                // and campaign_recipients.provider_message_id is globally unique. The key lives on
                // campaign_runs.zoho_campaign_key; derive it per recipient via campaign_run_id.
                CampaignRecipient::where('campaign_run_id', $run->id)
                    ->where('status', 'queued')
                    ->update([
                        'status'              => 'sent',
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
            });

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
    }

    /**
     * Local path: per-recipient SMTP/Mailpit send (unchanged).
     *
     * Re-queries recipients from the DB (does not reuse the caller's $contacts
     * collection). Per-recipient failures are logged and skipped — this path
     * does NOT rethrow, unlike sendViaZoho().
     */
    private function sendViaLocal(CampaignRun $run): void
    {
        /** @var CampaignsClient $driver */
        $driver = app(CampaignsClient::class);

        $recipients = CampaignRecipient::where('campaign_run_id', $run->id)
            ->where('status', 'queued')
            ->whereNull('provider_message_id')
            ->with('contact.company')
            ->get();

        $isPaced = $run->campaign->schedule_type === 'paced';
        $pacedTracking = collect();

        if ($isPaced && $recipients->isNotEmpty()) {
            $recipientIds = $recipients->pluck('id');
            $pacedTracking = EmailTrackingEvent::query()
                ->where('trackable_type', CampaignRecipient::class)
                ->whereIn('trackable_id', $recipientIds)
                ->get()
                ->keyBy('trackable_id');

            $now = now();
            $missingRows = $recipients
                ->reject(fn (CampaignRecipient $recipient): bool => $pacedTracking->has($recipient->id))
                ->map(fn (CampaignRecipient $recipient): array => [
                    'trackable_type' => CampaignRecipient::class,
                    'trackable_id' => $recipient->id,
                    'token' => TrackingToken::generate($run->id, $recipient->contact_id),
                    'event' => 'pending',
                    'human_open_count' => 0,
                    'machine_open_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            if ($missingRows !== []) {
                EmailTrackingEvent::query()->insert($missingRows);
                $pacedTracking = EmailTrackingEvent::query()
                    ->where('trackable_type', CampaignRecipient::class)
                    ->whereIn('trackable_id', $recipientIds)
                    ->get()
                    ->keyBy('trackable_id');
            }
        }

        // Prefetch the suppression list once instead of one query per recipient
        // (isSuppressed() is a plain normalized-equality lookup — see Suppression::isSuppressed()).
        $suppressed = Suppression::pluck('email')->map(fn ($e) => strtolower(trim($e)))->flip();

        // Count real send ATTEMPTS (recipients that pass the suppression re-check
        // and reach the try{} send below) — NOT $recipients->count(), which also
        // includes recipients skipped by the 4a suppression re-check. Using the
        // raw recipient count would mislabel an all-suppressed run (zero real
        // attempts) as 'failed' below.
        $attempted = 0;

        foreach ($recipients as $recipient) {
            $contact = $recipient->contact;

            // ── 4a. Send-time suppression re-check ───────────────────────────
            if (isset($suppressed[strtolower(trim($contact->email))])) {
                $recipient->update([
                    'status'      => 'skipped',
                    'skip_reason' => 'suppressed',
                ]);
                continue;
            }

            if ($isPaced) {
                $coldSendEnabled = (bool) config('prospecting.cold_send_enabled', false);

                if (! $coldSendEnabled && $contact->company?->relationship === 'prospect') {
                    $recipient->update([
                        'status' => 'skipped',
                        'skip_reason' => 'cold_send_disabled',
                    ]);
                    continue;
                }

                if ($coldSendEnabled
                    && $contact->company?->relationship === 'prospect'
                    && $contact->email_kind === 'personal') {
                    $recipient->update([
                        'status' => 'skipped',
                        'skip_reason' => 'personal_email',
                    ]);
                    continue;
                }
            }

            $attempted++;

            // ── 4b. Generate tracking token ──────────────────────────────────
            $trackingEvent = $isPaced
                ? $pacedTracking->get($recipient->id)
                : EmailTrackingEvent::query()
                    ->where('trackable_type', CampaignRecipient::class)
                    ->where('trackable_id', $recipient->id)
                    ->first();
            $token = $trackingEvent?->token
                ?? TrackingToken::generate($run->id, $contact->id);

            if (! $isPaced) {
                // Preserve the existing non-paced pre-send reservation.
                $trackingEvent = EmailTrackingEvent::createForSend($recipient, $token);
            }

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

                DB::transaction(function () use ($recipient, $trackingEvent, $pmid, $isPaced): void {
                    if ($isPaced) {
                        $trackingEvent->markSent();
                    }

                    // ── 4e. Record provider_message_id + sent status ───────────
                    $recipient->update([
                        'status'              => 'sent',
                        'provider_message_id' => $pmid,
                        'sent_at'             => now(),
                    ]);
                });
            } catch (\Throwable $e) {
                // Leave recipient in 'queued' state — next job retry will re-send.
                Log::error('[CampaignService] Failed to send to recipient.', [
                    'run_id'       => $run->id,
                    'recipient_id' => $recipient->id,
                    'contact_id'   => $contact->id,
                    'exception_class' => $e::class,
                ]);
            }
        }

        // ── Step 5 (local): Recompute run stats and mark complete ──────────
        $sentCount = CampaignRecipient::where('campaign_run_id', $run->id)
            ->whereNotNull('sent_at')
            ->count();

        if ($isPaced) {
            $queuedCount = CampaignRecipient::where('campaign_run_id', $run->id)
                ->where('status', 'queued')
                ->count();

            $run->update(['stats_sent' => $sentCount]);

            if ($queuedCount > 0) {
                // Keep status=sending and finished_at empty. Throwing is the
                // queue contract: SendCampaignJob retries only unsent rows.
                $run->update(['status' => 'sending', 'finished_at' => null]);
                throw new PacedCampaignRetryableException();
            }

            $this->markCompletedPacedDispatches($run);
            $run->update([
                'stats_sent' => $sentCount,
                'status' => 'sent',
                'finished_at' => now(),
            ]);

            Log::info('[CampaignService] Lot progressif complété (local).', [
                'run_id' => $run->id,
                'stats_sent' => $sentCount,
            ]);

            return;
        }

        // A run where every real send ATTEMPT failed (every per-recipient send
        // threw) must be marked 'failed', not 'sent' — a silent 0-delivered 'sent'
        // run previously masked a weeks-long prod SMTP outage. Gate on $attempted,
        // not $recipients->count(): an all-suppressed run (zero real attempts,
        // every recipient skipped at the 4a re-check) must stay 'sent'.
        $status = ($attempted > 0 && $sentCount === 0) ? 'failed' : 'sent';

        $run->update([
            'stats_sent'  => $sentCount,
            'status'      => $status,
            'finished_at' => now(),
        ]);

        Log::info('[CampaignService] Run complété (local).', [
            'run_id'     => $run->id,
            'stats_sent' => $sentCount,
        ]);
    }

    /** Finalize company ledgers after SendCampaignJob exhausts all retries. */
    public function finalizePacedFailure(CampaignRun $run, \Throwable $exception): void
    {
        $run->loadMissing('campaign');

        if ($run->campaign?->schedule_type !== 'paced') {
            return;
        }

        DB::transaction(function () use ($run): void {
            $dispatches = $run->companyDispatches()->lockForUpdate()->get();

            foreach ($dispatches as $dispatch) {
                if ($dispatch->recipients()->where('status', 'queued')->exists()) {
                    $dispatch->update([
                        'status' => 'failed',
                        'last_error' => 'Échec de livraison après épuisement des tentatives.',
                        'processed_at' => null,
                    ]);
                } else {
                    $dispatch->update([
                        'status' => 'processed',
                        'last_error' => null,
                        'processed_at' => now(),
                    ]);
                }
            }

            $sentCount = CampaignRecipient::where('campaign_run_id', $run->id)
                ->whereNotNull('sent_at')
                ->count();

            $run->update([
                'stats_sent' => $sentCount,
                'status' => $sentCount > 0 ? 'sent' : 'failed',
                'finished_at' => now(),
            ]);
        });

        Log::warning('[CampaignService] Lot progressif épuisé après les tentatives de file.', [
            'run_id' => $run->id,
            'exception_class' => $exception::class,
        ]);
    }

    private function markCompletedPacedDispatches(CampaignRun $run): void
    {
        foreach ($run->companyDispatches()->get() as $dispatch) {
            if (! $dispatch->recipients()->where('status', 'queued')->exists()) {
                $dispatch->update([
                    'status' => 'processed',
                    'last_error' => null,
                    'processed_at' => now(),
                ]);
            }
        }
    }
}
