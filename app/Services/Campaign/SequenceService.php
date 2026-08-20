<?php

namespace App\Services\Campaign;

use App\Jobs\SendSequenceStepJob;
use App\Jobs\SendSmtpReservationJob;
use App\Mail\SequenceStepMailable;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\EmailTrackingEvent;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Services\Scheduling\BusinessCalendarService;
use App\Services\Mail\SmtpMailRouter;
use App\Support\TrackingToken;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
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
 *   - Never hold a DB lock across a transport send (the firstOrCreate pattern
 *     follows the same claim-commit-then-send spirit as CampaignService).
 *
 * Hard rules (CLAUDE.md):
 *   - NEVER Mail::raw() — always a real Mailable (SequenceStepMailable).
 *   - Suppression check happens at send time (inside sendStep), not only at enroll time.
 */
class SequenceService
{
    public function __construct(
        private readonly BusinessCalendarService $calendar,
        private readonly SmtpSendReservationService $smtpReservations,
        private readonly ContactEligibilityService $contactEligibility,
    )
    {
    }

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
                // Deliberately UNSHIFTED, unlike advanceEnrollment()'s follow-up steps.
                // Enrolling is itself a deliberate act — step 1 goes out immediately even
                // on a Saturday/blackout day. Only follow-up steps shift to an allowed day.
                // Do NOT "fix" this to run through BusinessCalendarService.
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

                Log::channel('campaign')->info('[SequenceService] Duplicate enrollment caught (race or non-active row exists) — returning existing.', [
                    'sequence_id'    => $seq->id,
                    'contact_id'     => $contact->id,
                    'enrollment_id'  => $existing?->id,
                ]);

                return $existing;
            }

            // Any other QueryException is unexpected — rethrow.
            throw $e;
        }

        Log::channel('campaign')->info('[SequenceService] Contact enrolled.', [
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
            ->with(['campaign', 'sequence'])
            ->get();

        $eligible = $enrollments->filter($this->canSendViaSmtp(...))
            ->filter($this->isNotHeld(...));

        foreach ($eligible as $enrollment) {
            SendSequenceStepJob::dispatch($enrollment->id);
        }

        return $eligible->count();
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

        if (! $this->canSendViaSmtp($e)) {
            return;
        }

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

            Log::channel('campaign')->info('[SequenceService] Enrollment completed (no more steps).', [
                'enrollment_id' => $e->id,
                'last_step_no'  => $e->current_step,
            ]);

            return;
        }

        // ── 3. Send-time suppression check ────────────────────────────────────
        // SMTP accept-all is decided again by the reservation job with the
        // selected sender identity's real feedback health. Other quality rules
        // can be rejected before reserving a slot.
        $ineligibleReason = $this->contactEligibility->sendIneligibilityReasonForSingle(
            $contact,
            $e->campaign?->emailVerificationPolicy() ?? Campaign::VERIFICATION_VERIFIED_ONLY,
        );
        if ($ineligibleReason === 'verification_pending') {
            return;
        }
        if ($e->campaign?->delivery_channel !== 'smtp' && $ineligibleReason !== null) {
            $e->update([
                'status'         => 'stopped',
                'stopped_reason' => $ineligibleReason,
                'next_send_at'   => null,
            ]);

            Log::channel('campaign')->info('[SequenceService] Enrollment stopped — contact suppressed.', [
                'enrollment_id' => $e->id,
                'reason' => $ineligibleReason,
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
            Log::channel('campaign')->info('[SequenceService] Step already sent — skipping (retry-safe).', [
                'enrollment_id'       => $e->id,
                'step_no'             => $stepNo,
                'provider_message_id' => $stepSend->provider_message_id,
            ]);

            // Cursor may not have been advanced if the process died after the send
            // but before the advance. Re-advance now.
            $this->advanceEnrollment($e, $stepNo);

            return;
        }

        // Legacy/null campaigns keep their established path. Reservations are
        // only for an explicit user choice of SMTP.
        if ($e->campaign?->delivery_channel === 'smtp') {
            $result = $this->smtpReservations->reserve(
                $e->campaign->senderIdentity,
                $e->campaign,
                \App\Models\SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND,
                $stepSend->id,
            );
            if ($result['ok']) {
                SendSmtpReservationJob::dispatch($result['reservation']->id, $result['send_at'])->delay($result['send_at']);
            }

            return;
        }

        $finalReason = $this->contactEligibility->sendIneligibilityReasonForSingle(
            $contact,
            $e->campaign?->emailVerificationPolicy() ?? Campaign::VERIFICATION_VERIFIED_ONLY,
        );
        if ($finalReason !== null) {
            $stepSend->update(['status' => 'skipped']);
            $e->update([
                'status' => 'stopped',
                'stopped_reason' => $finalReason,
                'next_send_at' => null,
            ]);

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
                messageId:      'sequence-send-' . $stepSend->id . '@fretiq.local',
            );

            app(SmtpMailRouter::class)->send($senderIdentity, $contact->email, $mailable);

            // Use the message ID from the sent message when accessible; otherwise
            // generate a locally-unique reference (LocalCampaignsDriver pattern).
            $providerId = 'seq-' . $e->id . '-step-' . $stepNo . '-' . Str::random(8);

            // ── 10. Record provider_message_id + status='sent' ────────────────
            $stepSend->update([
                'provider_message_id' => $providerId,
                'status'              => 'sent',
                'sent_at'             => now(),
            ]);

            Log::channel('campaign')->info('[SequenceService] Step sent.', [
                'enrollment_id'       => $e->id,
                'step_no'             => $stepNo,
                'contact_email'       => $contact->email,
                'provider_message_id' => $providerId,
            ]);
        } catch (\Throwable $ex) {
            // Leave step_send in 'queued' status so retry picks it up.
            Log::channel('campaign')->error('[SequenceService] Failed to send step.', [
                'enrollment_id' => $e->id,
                'step_no'       => $stepNo,
                'error'         => $ex->getMessage(),
            ]);

            throw $ex; // Re-throw so the job marks itself for retry.
        }

        // ── 11. Advance enrollment cursor ─────────────────────────────────────
        $this->advanceEnrollment($e, $stepNo);
    }

    public function sendReservedStep(
        \App\Models\SmtpSendReservation $reservation,
        SmtpCampaignsDriver $driver,
        SmtpSendReservationService $reservations,
        ?callable $beforeTransport = null,
    ): void {
        $stepSend = SequenceStepSend::with(['enrollment.contact.company', 'enrollment.sequence', 'enrollment.campaign.senderIdentity'])
            ->find($reservation->source_id);
        if ($stepSend === null) {
            $reservations->release($reservation);
            return;
        }

        $enrollment = $stepSend->enrollment;
        $campaign = $enrollment->campaign;

        // SMTP acceptance is durable and must be reconciled before consulting
        // mutable campaign routing settings. Otherwise a sender/channel edit
        // can strand accepted sequence work indefinitely.
        if ($reservation->status === 'accepted') {
            if ($campaign !== null) {
                $this->finalizeAcceptedReservedStep($reservation, $stepSend, $campaign);
            }
            return;
        }

        if ($campaign === null || $campaign->delivery_channel !== 'smtp'
            || (int) $campaign->sender_identity_id !== (int) $reservation->sender_identity_id) {
            $reservations->release($reservation);
            return;
        }

        // Re-read state after the queue delay. A reply, pause, or already
        // advanced cursor must win over a stale reservation.
        $valid = DB::transaction(function () use ($stepSend, $enrollment, $campaign, $reservation): bool {
            $freshEnrollment = \App\Models\SequenceEnrollment::lockForUpdate()->find($enrollment->id);
            $freshStep = SequenceStepSend::lockForUpdate()->find($stepSend->id);
            $freshCampaign = Campaign::query()->find($campaign->id);
            $freshSequence = Sequence::query()->find($enrollment->sequence_id);

            return $freshEnrollment !== null && $freshStep !== null
                && $freshCampaign !== null && $freshSequence !== null
                && $freshEnrollment->status === 'active'
                && $freshCampaign->is_active && $freshSequence->is_active
                && $freshCampaign->delivery_channel === 'smtp'
                && (int) $freshCampaign->sender_identity_id === (int) $reservation->sender_identity_id
                && $freshEnrollment->next_send_at !== null
                && $freshEnrollment->next_send_at->lte(now())
                && (int) $freshEnrollment->current_step === ((int) $freshStep->step_no - 1)
                && $freshStep->status === 'queued';
        });
        if (! $valid) { $reservations->release($reservation); return; }

        if ($stepSend->provider_message_id !== null) {
            $reservations->markSent($reservation);
            $this->advanceEnrollment($enrollment, (int) $stepSend->step_no);
            return;
        }

        if (app(SmtpMailRouter::class)->usesSenderIdentityTransport()
            && ! $campaign->senderIdentity?->hasCompleteSmtpConfiguration()) {
            $reservations->defer($reservation, now()->addMinutes(5));
            return;
        }

        $contact = $enrollment->contact;
        $ineligibleReason = $this->contactEligibility->sendIneligibilityReasonForSingle(
            $contact,
            $campaign->emailVerificationPolicy(),
        );

        if ($ineligibleReason === 'verification_pending') {
            $reservations->defer($reservation, now()->addMinutes(5));

            return;
        }

        if ($ineligibleReason !== null) {
            $stepSend->update(['status' => 'skipped']);
            $enrollment->update([
                'status' => 'stopped',
                'stopped_reason' => $ineligibleReason,
                'next_send_at' => null,
            ]);
            $reservations->release($reservation);
            return;
        }

        $step = $enrollment->sequence->steps()
            ->where('step_no', $stepSend->step_no)
            ->with('template.translations')
            ->firstOrFail();
        $token = TrackingToken::generate($enrollment->id, (int) $stepSend->step_no);
        EmailTrackingEvent::createForSend($stepSend, $token);
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);
        if ($beforeTransport !== null) {
            $beforeTransport();
        }
        try {
            $providerId = $driver->sendSequence($stepSend, $enrollment, $step, $campaign->senderIdentity, $token, $unsubscribeUrl);
        } catch (\Throwable $exception) {
            $reservations->markUncertainAfterTransport($reservation);
            throw $exception;
        }

        try {
            \App\Models\SmtpSendReservation::whereKey($reservation->id)->update([
                'status' => 'accepted', 'accepted_at' => now(), 'provider_message_id' => $providerId, 'lease_expires_at' => null,
            ]);
        } catch (\Throwable $exception) {
            $reservations->markUncertainAfterTransport($reservation);
            throw $exception;
        }
        $reservation->refresh();
        $this->finalizeAcceptedReservedStep($reservation, $stepSend, $campaign);
    }

    private function finalizeAcceptedReservedStep(
        \App\Models\SmtpSendReservation $reservation,
        SequenceStepSend $stepSend,
        Campaign $campaign,
    ): void
    {
        DB::transaction(function () use ($stepSend, $campaign, $reservation): void {
            $fresh = \App\Models\SmtpSendReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($fresh->status !== 'accepted') {
                return;
            }

            $freshStep = SequenceStepSend::lockForUpdate()->findOrFail($stepSend->id);
            $freshEnrollment = SequenceEnrollment::lockForUpdate()->findOrFail($freshStep->enrollment_id);
            $freshStep->update([
                'provider_message_id' => $fresh->provider_message_id,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
            $fresh->update(['status' => 'sent', 'sent_at' => now(), 'lease_expires_at' => null]);
            $campaign->markDeliveryStarted();
            $this->advanceEnrollment($freshEnrollment, (int) $freshStep->step_no);
        });
    }

    // ── Stop on reply ──────────────────────────────────────────────────────────

    /**
     * Stop all active enrollments for a contact. A reply is a universal stop signal.
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
            ->get();

        $stopped = 0;

        foreach ($enrollments as $enrollment) {
            $enrollment->update([
                'status'         => 'stopped',
                'stopped_reason' => $reason,
                'next_send_at'   => null,
            ]);

            $stopped++;

            Log::channel('campaign')->info('[SequenceService] Enrollment stopped on reply.', [
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
        $e->refresh()->load(['sequence', 'campaign']);

        $nextStep = $e->sequence->steps()->where('step_no', $stepNo + 1)->first();

        if ($nextStep !== null) {
            $e->update([
                'current_step' => $stepNo,
                'last_sent_at' => now(),
                // SHIFT, never skip — a follow-up step must always eventually fire;
                // skipping would silently drop a sequence step.
                'next_send_at' => $this->calendar->shiftToAllowed(
                    now()->addDays((int) ($nextStep->delay_days ?? 1)),
                    $this->calendar->resolveTimezone($e->campaign),
                ),
            ]);
        } else {
            $e->update([
                'current_step' => $stepNo,
                'last_sent_at' => now(),
                'status'       => 'completed',
                'next_send_at' => null,
            ]);

            Log::channel('campaign')->info('[SequenceService] Enrollment completed after last step.', [
                'enrollment_id' => $e->id,
                'final_step_no' => $stepNo,
            ]);
        }
    }

    private function canSendViaSmtp(SequenceEnrollment $enrollment): bool
    {
        if ($enrollment->status !== 'active' || ! $enrollment->sequence?->is_active) {
            return false;
        }

        if ($enrollment->campaign === null) {
            return true;
        }

        if ($enrollment->campaign?->delivery_channel === 'smtp') {
            return $enrollment->campaign->is_active;
        }

        if (config('services.zoho.driver', 'local') === 'zoho') {
            return false;
        }

        // The global local driver is the Mailpit safety fallback for both
        // legacy/null and explicit Zoho campaigns, including paced enrollments.
        return $enrollment->campaign->is_active;
    }

    /**
     * Defensive hold gate for processDue(): when TODAY (the current moment,
     * resolved in the enrollment's timezone) is a blocked day (weekend/
     * blackout), the enrollment is simply NOT dispatched this tick — it stays
     * untouched and fires on the next allowed day, since next_send_at <= now()
     * remains true for as long as the row is never mutated. This deliberately
     * checks the CURRENT moment, not the stored next_send_at value — mirroring
     * SendWindowGuard::isWithinSendWindow(), which also gates on Carbon::now(),
     * not on the run's own scheduled timestamp. Checking next_send_at itself
     * would hold a stale Saturday-dated row FOREVER, since that column is never
     * mutated by this gate.
     *
     * `campaign` is already eager-loaded by processDue(), so this costs zero
     * extra queries. Logged at debug only — this fires for every held
     * enrollment on every minute tick.
     */
    private function isNotHeld(SequenceEnrollment $enrollment): bool
    {
        $tz = $this->calendar->resolveTimezone($enrollment->campaign);

        if ($this->calendar->isBlocked(now(), $tz)) {
            Log::channel('campaign')->debug('[SequenceService] Enrollment held — today is a blocked day.', [
                'enrollment_id' => $enrollment->id,
                'next_send_at'  => $enrollment->next_send_at?->toIso8601String(),
                'tz'            => $tz,
            ]);

            return false;
        }

        return true;
    }
}
