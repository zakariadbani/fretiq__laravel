<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SequenceEnrollment;
use App\Services\Campaign\ContactEligibilityService;
use App\Services\Scheduling\BusinessCalendarService;
use Illuminate\Console\Command;

/**
 * sequences:repair-stalled — idempotent data-repair sweep for the sequence
 * scheduling stall (prod, 2026-08-23): active enrollments left with
 * next_send_at=NULL and no live wave tracking, plus campaign_recipients
 * stuck 'queued' on a CampaignRun that already reached a terminal status.
 *
 * The root causes are fixed at the source (PacedSequenceEnrollmentService::
 * evaluateDue(), SequenceWaveService::send() and ::adoptLegacyEnrollments()
 * no longer null out next_send_at for wave-managed enrollments) — this
 * command repairs rows that stalled BEFORE that fix shipped. It reuses
 * BusinessCalendarService + ContactEligibilityService; no scheduling or
 * eligibility rule is reimplemented here.
 *
 * DRY-RUN by default (prints counts only); pass --execute to apply. Safe to
 * run repeatedly — a second run finds nothing left to repair.
 */
class SequencesRepairStalled extends Command
{
    protected $signature = 'sequences:repair-stalled {--execute : Apply the repair instead of only reporting counts}';

    protected $description = 'Repair active sequence enrollments stuck with next_send_at=NULL and orphaned queued campaign_recipients.';

    /**
     * Safety margin for the stale-past selector in repairEnrollments(): how
     * far overdue an active enrollment's next_send_at must be before it is
     * treated as stalled rather than merely "due soon, worker hasn't picked
     * it up yet".
     */
    private const STALE_AFTER_HOURS = 24;

    public function handle(BusinessCalendarService $calendar, ContactEligibilityService $contactEligibility): int
    {
        $execute = (bool) $this->option('execute');

        [$rescheduled, $completed] = $this->repairEnrollments($calendar, $execute);
        [$skipped, $leftAlone] = $this->repairStuckRecipients($contactEligibility, $execute);

        $mode = $execute ? 'APPLIED' : 'DRY-RUN (pass --execute to apply)';
        $this->info("[{$mode}]");
        $this->info("Enrollments rescheduled (next_send_at recomputed): {$rescheduled}");
        $this->info("Enrollments completed (no further step exists): {$completed}");
        $this->info("Recipients marked skipped (terminally ineligible/stale): {$skipped}");
        $this->info("Recipients left queued (requeue-able, resolve on next run): {$leftAlone}");

        return self::SUCCESS;
    }

    /**
     * Active enrollments needing repair come in two shapes: legacy
     * next_send_at=NULL (pre-fix stall, always safe to recompute), and
     * post-fix next_send_at stuck in the past beyond STALE_AFTER_HOURS —
     * that second shape only counts as stalled when its owning wave run is
     * gone or terminal; a live (prepared/scheduled/sending) wave run means
     * the enrollment is still legitimately tracked and simply not due yet.
     * Recompute from last_sent_at + next step's delay_days (or "due now"
     * when never sent — step 1 is deliberately unshifted, mirroring
     * SequenceService::enroll()), respecting business-day/window rules via
     * BusinessCalendarService. An enrollment with no further step left is
     * itself a data bug (should already be 'completed') — repaired by
     * transitioning to that terminal status instead, per the same "valid
     * next_send_at OR terminal" rule.
     *
     * @return array{0: int, 1: int} [rescheduled, completed]
     */
    private function repairEnrollments(BusinessCalendarService $calendar, bool $execute): array
    {
        $rescheduled = 0;
        $completed = 0;

        $staleCutoff = now()->subHours(self::STALE_AFTER_HOURS);

        SequenceEnrollment::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('next_send_at')->orWhere('next_send_at', '<', $staleCutoff))
            ->with(['sequence.steps', 'campaign'])
            ->chunkById(500, function ($chunk) use ($calendar, $execute, &$rescheduled, &$completed): void {
                // One CampaignRun prefetch query per chunk instead of the old
                // whereHas-per-row lookup — mirrors repairStuckRecipients()'s
                // chunk-then-prefetch pattern below.
                $owningRuns = $this->prefetchOwningWaveRuns($chunk);

                foreach ($chunk as $enrollment) {
                    if ($enrollment->next_send_at !== null
                        && ! $this->owningWaveRunIsTerminalOrAbsent($enrollment, $owningRuns)) {
                        continue;
                    }

                    $nextStep = $enrollment->sequence?->steps
                        ->where('step_no', '>', (int) $enrollment->current_step)
                        ->sortBy('step_no')
                        ->first();

                    if ($nextStep === null) {
                        $completed++;
                        if ($execute) {
                            $enrollment->update(['status' => 'completed', 'next_send_at' => null]);
                        }
                        continue;
                    }

                    if ($enrollment->last_sent_at === null) {
                        // Never sent a single step yet — due now, the same
                        // deliberately-unshifted rule as SequenceService::enroll().
                        $nextSendAt = now();
                    } else {
                        $nextSendAt = $calendar->shiftToAllowed(
                            $enrollment->last_sent_at->copy()->addDays((int) ($nextStep->delay_days ?? 1)),
                            $calendar->resolveTimezone($enrollment->campaign),
                        );
                    }

                    $rescheduled++;
                    if ($execute) {
                        $enrollment->update(['next_send_at' => $nextSendAt]);
                    }
                }
            });

        return [$rescheduled, $completed];
    }

    /**
     * Batch-prefetch, once per chunk, every CampaignRun that could "own" a
     * stale-past enrollment's next step — replaces the old one-whereHas-
     * query-per-row lookup. Keyed exactly like owningWaveRunIsTerminalOrAbsent()
     * looks them up: "campaignId:stepId:contactId".
     *
     * @param  \Illuminate\Support\Collection<int, SequenceEnrollment>  $enrollments
     * @return array<string, CampaignRun>
     */
    private function prefetchOwningWaveRuns($enrollments): array
    {
        $campaignIds = [];
        $stepIds = [];

        foreach ($enrollments as $enrollment) {
            if ($enrollment->next_send_at === null) {
                continue; // unconditionally stalled — never looked up.
            }

            $nextStep = $enrollment->sequence?->steps
                ->where('step_no', '>', (int) $enrollment->current_step)
                ->sortBy('step_no')
                ->first();

            if ($nextStep === null) {
                continue;
            }

            $campaignIds[] = $enrollment->campaign_id;
            $stepIds[] = $nextStep->id;
        }

        if ($campaignIds === []) {
            return [];
        }

        $map = [];
        CampaignRun::query()
            ->whereIn('campaign_id', array_unique($campaignIds))
            ->whereIn('sequence_step_id', array_unique($stepIds))
            ->with(['recipients:id,campaign_run_id,contact_id'])
            ->get()
            ->each(function (CampaignRun $run) use (&$map): void {
                foreach ($run->recipients as $recipient) {
                    $key = $run->campaign_id . ':' . $run->sequence_step_id . ':' . $recipient->contact_id;
                    $map[$key] ??= $run;
                }
            });

        return $map;
    }

    /**
     * The wave run "owning" an enrollment's next step is found the same way
     * SequenceWaveService::adoptLegacyEnrollments() finds it: a
     * CampaignRecipient row for this contact on the CampaignRun tied to the
     * enrollment's next step. Used only for the stale-past selector — a
     * NULL next_send_at is unconditionally stalled and never reaches here.
     *
     * @param  array<string, CampaignRun>  $owningRuns  From prefetchOwningWaveRuns().
     */
    private function owningWaveRunIsTerminalOrAbsent(SequenceEnrollment $enrollment, array $owningRuns): bool
    {
        $nextStep = $enrollment->sequence?->steps
            ->where('step_no', '>', (int) $enrollment->current_step)
            ->sortBy('step_no')
            ->first();

        if ($nextStep === null) {
            return true;
        }

        $key = $enrollment->campaign_id . ':' . $nextStep->id . ':' . $enrollment->contact_id;
        $run = $owningRuns[$key] ?? null;

        return $run === null || in_array($run->status, ['sent', 'failed', 'canceled'], true);
    }

    /**
     * campaign_recipients stuck 'queued' whose sequence-wave CampaignRun
     * already reached a terminal status (sent/failed/canceled) — that run
     * will never be revisited by SequenceWaveService::recover(). Business
     * rule: terminally ineligible or stale (the enrollment/contact can no
     * longer receive this exact step) -> mark skipped with a reason;
     * genuinely still requeue-able -> leave untouched, it resolves once the
     * owning enrollment (repaired above) is picked up again.
     *
     * Chunked (this is the same order of magnitude as the incident's ~437k
     * orphan rows) with the matching enrollments prefetched once per chunk
     * instead of one query per recipient.
     *
     * @return array{0: int, 1: int} [skipped, leftAlone]
     */
    private function repairStuckRecipients(ContactEligibilityService $contactEligibility, bool $execute): array
    {
        $skipped = 0;
        $leftAlone = 0;

        CampaignRecipient::query()
            ->where('status', 'queued')
            ->whereHas('run', fn ($query) => $query
                ->whereNotNull('sequence_step_id')
                ->whereIn('status', ['sent', 'failed', 'canceled']))
            ->with(['contact.company', 'run.sequenceStep', 'run.campaign'])
            ->chunkById(500, function ($recipients) use ($contactEligibility, $execute, &$skipped, &$leftAlone): void {
                // Enrollments carry a nullable campaign_id (standalone
                // sequences — see sequence_enrollments migration). Without
                // the OR-null leg + fallback lookup key below, those rows'
                // recipients would never match and fall through to
                // 'orphaned_recipient' purely because the enrollment itself
                // was never attributed to a campaign.
                $enrollments = SequenceEnrollment::query()
                    ->whereIn('contact_id', $recipients->pluck('contact_id')->unique())
                    ->where(fn ($query) => $query
                        ->whereIn('campaign_id', $recipients->pluck('run.campaign_id')->unique())
                        ->orWhereNull('campaign_id'))
                    ->get()
                    ->groupBy(fn (SequenceEnrollment $enrollment): string => $enrollment->campaign_id === null
                        ? 'null:' . $enrollment->contact_id
                        : $enrollment->campaign_id . ':' . $enrollment->contact_id)
                    ->map(fn ($group) => $group->first());

                foreach ($recipients as $recipient) {
                    $run = $recipient->run;
                    $stepNo = (int) $run->sequenceStep?->step_no;
                    $enrollment = $enrollments->get($run->campaign_id . ':' . $recipient->contact_id)
                        ?? $enrollments->get('null:' . $recipient->contact_id);

                    $reason = $this->stuckRecipientReason($recipient, $run, $stepNo, $enrollment, $contactEligibility);

                    if ($reason === null) {
                        $leftAlone++;
                        continue;
                    }

                    $skipped++;
                    if ($execute) {
                        $recipient->update(['status' => 'skipped', 'skip_reason' => $reason]);
                    }
                }
            });

        return [$skipped, $leftAlone];
    }

    private function stuckRecipientReason(
        CampaignRecipient $recipient,
        CampaignRun $run,
        int $stepNo,
        ?SequenceEnrollment $enrollment,
        ContactEligibilityService $contactEligibility,
    ): ?string {
        if ($enrollment === null) {
            return 'orphaned_recipient';
        }
        if ($enrollment->status === 'stopped') {
            return $enrollment->stopped_reason ?? 'enrollment_stopped';
        }
        if ($enrollment->status === 'completed') {
            return 'enrollment_completed';
        }
        if ($enrollment->status !== 'active') {
            return 'enrollment_' . $enrollment->status;
        }
        if ($run->sequenceStep === null) {
            return 'missing_step';
        }
        if ((int) $enrollment->current_step !== $stepNo - 1) {
            return 'stale_run';
        }
        if ($recipient->contact === null) {
            return 'missing_contact';
        }

        return $contactEligibility->sendIneligibilityReasonForSingle(
            $recipient->contact,
            $run->campaign?->emailVerificationPolicy() ?? Campaign::VERIFICATION_VERIFIED_ONLY,
        );
    }
}
