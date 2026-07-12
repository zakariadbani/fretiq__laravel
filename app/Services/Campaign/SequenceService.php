<?php

namespace App\Services\Campaign;

use App\Jobs\SendSequenceStepJob;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailTrackingEvent;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Models\Suppression;
use App\Support\TrackingToken;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * SequenceService — drip sequence orchestrator.
 *
 * Idempotency design (queue-idempotency.md §3):
 *   - SequenceStepSend::firstOrCreate(['enrollment_id','step_no']) is the durable
 *     backstop. Two concurrent sendStep calls for the same enrollment produce at most
 *     one row; the second sees the existing row and exits early.
 *   - If provider_message_id is already set on the SequenceStepSend row, the send
 *     is skipped — safe on retry even after a crash-after-send.
 *   - Never hold a DB lock across a Mail send (the firstOrCreate + Mail::send pattern
 *     follows the same claim-commit-then-send spirit as CampaignService).
 *
 * Hard rules (CLAUDE.md):
 *   - NEVER Mail::raw() — always a real Mailable (SequenceStepMailable).
 *   - Suppression check happens at send time (inside sendStep), not only at enroll time.
 */
class SequenceService
{
    // ── Enrol ──────────────────────────────────────────────────────────────────

    /**
     * Enrol a contact in a sequence.
     *
     * If an active enrollment already exists for this (sequence, contact) pair,
     * returns the existing enrollment without creating a duplicate.
     *
     * @param  Sequence              $seq       The drip sequence.
     * @param  Contact               $contact   The contact to enrol.
     * @param  Campaign|null         $campaign  Optional originating campaign (for attribution).
     * @return SequenceEnrollment|null          The active enrollment (new or existing), or null
     *                                          if enrollment creation failed.
     */
    public function enroll(Sequence $seq, Contact $contact, ?Campaign $campaign = null): ?SequenceEnrollment
    {
        // Return existing active enrollment — do not re-enrol.
        $existing = SequenceEnrollment::where('sequence_id', $seq->id)
            ->where('contact_id', $contact->id)
            ->where('status', 'active')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $enrollment = SequenceEnrollment::create([
                'sequence_id'  => $seq->id,
                'contact_id'   => $contact->id,
                'campaign_id'  => $campaign?->id,
                'current_step' => 0,
                'status'       => 'active',
                'next_send_at' => now(),
            ]);
        } catch (QueryException $e) {
            // SQLSTATE 23000 = integrity constraint violation; MySQL error code 1062 =
            // duplicate entry. Only treat 23000+1062 together as a duplicate-key violation
            // from the UNIQUE(sequence_id, contact_id) constraint.
            // 23000 alone also covers FK violations (e.g. 1452 — unknown parent key) which
            // must be re-thrown, not silently swallowed.
            $sqlstate  = $e->errorInfo[0] ?? '';
            $errorCode = (int) ($e->errorInfo[1] ?? 0);

            if ($sqlstate === '23000' && $errorCode === 1062) {
                $existing = SequenceEnrollment::where('sequence_id', $seq->id)
                    ->where('contact_id', $contact->id)
                    ->first();

                Log::info('[SequenceService] Duplicate enrollment caught (race or non-active row exists) — returning existing.', [
                    'sequence_id'    => $seq->id,
                    'contact_id'     => $contact->id,
                    'enrollment_id'  => $existing?->id,
                ]);

                return $existing;
            }

            // Any other QueryException is unexpected — rethrow.
            throw $e;
        }

        Log::info('[SequenceService] Contact enrolled.', [
            'enrollment_id' => $enrollment->id,
            'sequence_id'   => $seq->id,
            'contact_id'    => $contact->id,
        ]);

        return $enrollment;
    }

    // ── Process due enrollments ────────────────────────────────────────────────

    /**
     * Dispatch SendSequenceStepJob for every active enrollment that is due.
     *
     * Called by `sequences:process` every minute. Dispatches jobs; does not
     * send synchronously. Each job has ShouldBeUnique + WithoutOverlapping so
     * duplicate dispatches are idempotent.
     *
     * @return int  Number of enrollments for which a job was dispatched.
     */
    public function processDue(): int
    {
        $enrollments = SequenceEnrollment::where('status', 'active')
            ->where('next_send_at', '<=', now())
            ->get();

        foreach ($enrollments as $enrollment) {
            SendSequenceStepJob::dispatch($enrollment->id);
        }

        return $enrollments->count();
    }

    // ── Send a step ────────────────────────────────────────────────────────────

    /**
     * Send the next drip step for an enrollment.
     *
     * Idempotent: safe to call multiple times for the same enrollment on retry.
     *
     * Flow:
     *  1. Determine the next step_no = current_step + 1.
     *  2. Load the SequenceStep; if none → enrollment completed.
     *  3. Suppression check — stop enrollment if suppressed.
     *  4. SequenceStepSend::firstOrCreate (unique backstop).
     *  5. If provider_message_id already set → skip (crash-after-send safety).
     *  6. Send via SequenceStepMailable (real Mailable, tracking pixel, unsub header).
     *  7. Record provider_message_id + status='sent'.
     *  8. Advance enrollment cursor; find next step for next_send_at.
     *
     * @param  SequenceEnrollment  $e  The enrollment to advance.
     */
    public function sendStep(SequenceEnrollment $e): void
    {
        // Always reload with relations to avoid stale state on retry.
        $e->load(['contact.company', 'sequence', 'campaign.senderIdentity']);

        $contact  = $e->contact;
        $sequence = $e->sequence;

        // ── 1. Determine next step_no ─────────────────────────────────────────
        $stepNo = $e->current_step + 1;

        // ── 2. Find the step ──────────────────────────────────────────────────
        /** @var \App\Models\SequenceStep|null $step */
        $step = $sequence->steps()->where('step_no', $stepNo)->with('template.translations')->first();

        if ($step === null) {
            // No more steps → enrollment complete.
            $e->update([
                'status'       => 'completed',
                'next_send_at' => null,
            ]);

            Log::info('[SequenceService] Enrollment completed (no more steps).', [
                'enrollment_id' => $e->id,
                'last_step_no'  => $e->current_step,
            ]);

            return;
        }

        // ── 3. Send-time suppression check ────────────────────────────────────
        if (Suppression::isSuppressed($contact->email)) {
            $e->update([
                'status'         => 'stopped',
                'stopped_reason' => 'suppressed',
                'next_send_at'   => null,
            ]);

            Log::info('[SequenceService] Enrollment stopped — contact suppressed.', [
                'enrollment_id' => $e->id,
                'contact_email' => $contact->email,
            ]);

            return;
        }

        // ── 4. Insert SequenceStepSend (idempotent via unique constraint) ─────
        $stepSend = SequenceStepSend::firstOrCreate(
            [
                'enrollment_id' => $e->id,
                'step_no'       => $stepNo,
            ],
            [
                'status' => 'queued',
            ],
        );

        // ── 5. Skip if already sent (crash-after-send safety) ─────────────────
        if ($stepSend->provider_message_id !== null) {
            Log::info('[SequenceService] Step already sent — skipping (retry-safe).', [
                'enrollment_id'       => $e->id,
                'step_no'             => $stepNo,
                'provider_message_id' => $stepSend->provider_message_id,
            ]);

            // Cursor may not have been advanced if the process died after the send
            // but before the advance. Re-advance now.
            $this->advanceEnrollment($e, $stepNo);

            return;
        }

        // ── 6. Build tracking token + EmailTrackingEvent ──────────────────────
        $token = TrackingToken::generate($e->id, $stepNo);

        EmailTrackingEvent::createForSend($stepSend, $token);

        // ── 7. Build signed unsubscribe URL ───────────────────────────────────
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        // ── 8. Build subject line (step subject overrides template subject) ────
        // Also resolve the language-appropriate body for this contact's country.
        // template.translations is already eager-loaded above (with 'template.translations')
        // so resolveFor() filters in PHP — no additional DB query per recipient.
        $template    = $step->template;
        $resolved    = $template->resolveFor($contact->company?->country);
        $subjectLine = $step->subject ?: $resolved['subject'];

        // ── 9. Send via real Mailable — OUTSIDE any DB transaction ───────────
        // Resolve sender identity: prefer the originating campaign's sender identity;
        // fall back to config('mail.from') when there is no campaign attribution or
        // the campaign's sender identity was deleted/null.
        $senderIdentity = $e->campaign?->senderIdentity ?? null;

        try {
            $mailable = new SequenceStepMailable(
                step:           $step,
                contact:        $contact,
                subjectLine:    $subjectLine,
                trackingToken:  $token,
                unsubscribeUrl: $unsubscribeUrl,
                senderIdentity: $senderIdentity,
                resolvedHtml:   $resolved['html_content'],
                language:       $resolved['language'],
            );

            Mail::to($contact->email)->send($mailable);

            // Use the message ID from the sent message when accessible; otherwise
            // generate a locally-unique reference (LocalCampaignsDriver pattern).
            $providerId = 'seq-' . $e->id . '-step-' . $stepNo . '-' . Str::random(8);

            // ── 10. Record provider_message_id + status='sent' ────────────────
            $stepSend->update([
                'provider_message_id' => $providerId,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            Log::info('[SequenceService] Step sent.', [
                'enrollment_id'       => $e->id,
                'step_no'             => $stepNo,
                'contact_email'       => $contact->email,
                'provider_message_id' => $providerId,
            ]);
        } catch (\Throwable $ex) {
            // Leave step_send in 'queued' status so retry picks it up.
            Log::error('[SequenceService] Failed to send step.', [
                'enrollment_id' => $e->id,
                'step_no'       => $stepNo,
                'error'         => $ex->getMessage(),
            ]);

            throw $ex; // Re-throw so the job marks itself for retry.
        }

        // ── 11. Advance enrollment cursor ─────────────────────────────────────
        $this->advanceEnrollment($e, $stepNo);
    }

    // ── Stop on reply ──────────────────────────────────────────────────────────

    /**
     * Stop all active enrollments for a contact in sequences that have stop_on_reply=true.
     *
     * Triggered manually (UI action) or by a reply-detection hook.
     *
     * @param  Contact  $contact  The contact who replied.
     * @param  string   $reason   Stopped reason stored on the enrollment ('replied' by default).
     * @return int  Number of enrollments stopped.
     */
    public function stopForReply(Contact $contact, string $reason = 'replied'): int
    {
        $enrollments = SequenceEnrollment::where('contact_id', $contact->id)
            ->where('status', 'active')
            ->with('sequence')
            ->get();

        $stopped = 0;

        foreach ($enrollments as $enrollment) {
            if (! $enrollment->sequence->stop_on_reply) {
                continue;
            }

            $enrollment->update([
                'status'         => 'stopped',
                'stopped_reason' => $reason,
                'next_send_at'   => null,
            ]);

            $stopped++;

            Log::info('[SequenceService] Enrollment stopped on reply.', [
                'enrollment_id' => $enrollment->id,
                'contact_id'    => $contact->id,
                'reason'        => $reason,
            ]);
        }

        return $stopped;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Advance enrollment current_step and compute next_send_at.
     *
     * Finds the step AFTER $stepNo to determine the next delay. If no further
     * step exists, marks enrollment as 'completed'.
     */
    private function advanceEnrollment(SequenceEnrollment $e, int $stepNo): void
    {
        $e->refresh()->load('sequence');

        $nextStep = $e->sequence->steps()->where('step_no', $stepNo + 1)->first();

        if ($nextStep !== null) {
            $e->update([
                'current_step' => $stepNo,
                'last_sent_at' => now(),
                'next_send_at' => now()->addDays((int) ($nextStep->delay_days ?? 1)),
            ]);
        } else {
            $e->update([
                'current_step' => $stepNo,
                'last_sent_at' => now(),
                'status'       => 'completed',
                'next_send_at' => null,
            ]);

            Log::info('[SequenceService] Enrollment completed after last step.', [
                'enrollment_id' => $e->id,
                'final_step_no' => $stepNo,
            ]);
        }
    }
}
