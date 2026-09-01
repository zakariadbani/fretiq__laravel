<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Jobs\SendSequenceWaveStepJob;
use App\Jobs\SyncCampaignWaveZohoListJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Models\Setting;
use App\Models\Suppression;
use App\Services\Scheduling\BusinessCalendarService;
use App\Services\Zoho\ZohoRecipientListGateway;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SequenceWaveService
{
    public function __construct(
        private readonly ZohoCampaignsDriver $driver,
        private readonly BusinessCalendarService $calendar,
        private readonly ZohoRecipientListGateway $listGateway,
        private readonly CampaignDeliveryFence $deliveryFence,
        private readonly ContactEligibilityService $contactEligibility,
    ) {}

    public function isDeferred(CampaignRun $run): bool
    {
        $run->loadMissing(['campaign.sequence', 'recipients']);
        if (! $run->campaign?->sequence?->is_active) {
            return true;
        }

        $queuedContactIds = $run->recipients->where('status', 'queued')->pluck('contact_id');

        return $queuedContactIds->isNotEmpty()
            && SequenceEnrollment::query()
                ->where('campaign_id', $run->campaign_id)
                ->whereIn('contact_id', $queuedContactIds)
                ->where('status', 'paused')
                ->exists();
    }

    public function hasPendingVerification(CampaignRun $run): bool
    {
        $run->loadMissing(['campaign', 'recipients.contact']);
        if ($run->campaign?->emailVerificationPolicy() !== Campaign::VERIFICATION_ALL_SENDABLE) {
            return false;
        }

        return $run->recipients->where('status', 'queued')->contains(
            fn (CampaignRecipient $recipient): bool => $recipient->contact?->email_verification_status === 'pending',
        );
    }
    /** @return Collection<int, \App\Models\Contact> */
    public function eligibleContacts(CampaignRun $run): Collection
    {
        $run->loadMissing(['campaign', 'sequenceStep', 'recipients.contact.company']);
        if ($run->sequenceStep === null) {
            return $run->recipients->where('status', 'queued')->pluck('contact')->filter()->values();
        }
        $stepNo = (int) $run->sequenceStep->step_no;
        $contacts = collect();
        $suppressedEmails = Suppression::query()->pluck('email')
            ->mapWithKeys(fn ($email): array => [mb_strtolower(trim((string) $email)) => true]);

        foreach ($run->recipients->where('status', 'queued') as $recipient) {
            $enrollment = SequenceEnrollment::query()
                ->where('campaign_id', $run->campaign_id)
                ->where('contact_id', $recipient->contact_id)
                ->first();
            if ($enrollment?->status === 'paused') {
                continue;
            }
            $normalizedEmail = mb_strtolower(trim((string) $recipient->contact?->email));
            $suppressed = $recipient->contact === null || isset($suppressedEmails[$normalizedEmail]);
            $qualityReason = $recipient->contact === null ? 'missing_contact' : $this->contactEligibility->sendIneligibilityReason(
                $recipient->contact,
                $run->campaign?->emailVerificationPolicy() ?? \App\Models\Campaign::VERIFICATION_VERIFIED_ONLY,
                $suppressed,
            );
            $eligible = $enrollment !== null
                && $enrollment->status === 'active'
                && (int) $enrollment->current_step === $stepNo - 1
                && $qualityReason === null;

            if ($eligible) {
                $contacts->push($recipient->contact);
                continue;
            }

            $reason = $qualityReason ?? 'enrollment_ineligible';
            $recipient->update(['status' => 'skipped', 'skip_reason' => $reason]);
            if ($enrollment !== null) {
                SequenceStepSend::updateOrCreate(
                    ['enrollment_id' => $enrollment->id, 'step_no' => $stepNo],
                    ['campaign_run_id' => $run->id, 'status' => 'skipped'],
                );
                if ($qualityReason !== null && $enrollment->status === 'active') {
                    $enrollment->update(['status' => 'stopped', 'stopped_reason' => $qualityReason, 'next_send_at' => null]);
                }
            }
        }

        return $contacts;
    }

    /**
     * Global daily send cap for sequence Zoho-wave sends. Feature is OFF by
     * default (cap <= 0) — a total no-op, zero behavior change. When on,
     * shrinks/reschedules this run's queued recipients to fit the remaining
     * daily budget instead of letting a large backlog blow past the cap in
     * one shot: the surplus moves to a sibling run on the next business day,
     * draining unbounded backlogs in daily slices without ever double-sending
     * or stranding a recipient.
     *
     * @return 'proceed'|'split'|'deferred'
     */
    public function enforceDailyCap(CampaignRun $run): string
    {
        $cap = (int) Setting::get('planification.daily_send_cap', 0);
        if ($cap <= 0) {
            return 'proceed';
        }
        $tz = (string) config('prospecting.smtp.quota_timezone', 'Europe/Paris');

        return DB::transaction(function () use ($run, $cap, $tz): string {
            $locked = CampaignRun::query()->lockForUpdate()->findOrFail($run->id);
            $remaining = $this->remainingDailyBudget($cap, $tz);

            $queued = CampaignRecipient::query()
                ->where('campaign_run_id', $locked->id)
                ->where('status', 'queued')
                ->with('contact.company')
                ->get()
                ->sort(function (CampaignRecipient $a, CampaignRecipient $b): int {
                    $scoreA = $a->contact?->company?->ai_score ?? -1;
                    $scoreB = $b->contact?->company?->ai_score ?? -1;
                    if ($scoreA !== $scoreB) {
                        return $scoreB <=> $scoreA;
                    }
                    $companyA = $a->contact?->company_id ?? 0;
                    $companyB = $b->contact?->company_id ?? 0;
                    if ($companyA !== $companyB) {
                        return $companyA <=> $companyB;
                    }
                    return $a->contact_id <=> $b->contact_id;
                })
                ->values();

            if ($queued->count() <= $remaining) {
                return 'proceed';
            }

            if ($remaining <= 0) {
                $deferAt = $this->nextBusinessDaySameWallTime($locked, $tz);
                $locked->update([
                    'status' => 'prepared',
                    'run_at' => $deferAt,
                ]);
                // Mirror the split path (see deferSurplusToNextBusinessDay): keep the
                // enrollments' next_send_at aligned with the run's bumped run_at for
                // observability. Not load-bearing — recover() drives off run_at.
                SequenceEnrollment::query()
                    ->where('campaign_id', $locked->campaign_id)
                    ->whereIn('contact_id', $queued->pluck('contact_id'))
                    ->where('status', 'active')
                    ->update(['next_send_at' => $deferAt]);
                return 'deferred';
            }

            $surplus = $queued->slice($remaining)->values();
            $this->deferSurplusToNextBusinessDay($locked, $surplus, $tz);
            return 'split';
        }, 3);
    }

    /** Cap minus everything already sent today (global, across all campaigns), in the quota timezone's day boundary. */
    private function remainingDailyBudget(int $cap, string $tz): int
    {
        $today = now($tz);
        $dayStart = $today->copy()->startOfDay()->utc();
        $dayEnd = $today->copy()->endOfDay()->utc();

        $sentToday = SequenceStepSend::query()
            ->where('status', 'sent')
            ->whereBetween('sent_at', [$dayStart, $dayEnd])
            ->count();

        return $cap - $sentToday;
    }

    /** Next allowed business day, at the run's own wall-clock hh:mm, in $tz. */
    private function nextBusinessDaySameWallTime(CampaignRun $run, string $tz): Carbon
    {
        $wallTime = $run->run_at->copy()->setTimezone($tz);

        return $this->calendar->shiftToAllowed(
            now($tz)->addDay()->setTime($wallTime->hour, $wallTime->minute, 0),
            $tz,
        );
    }

    /**
     * Move the surplus (over-cap) recipients of $run to a sibling CampaignRun
     * scheduled for the next business day — create-on-deferred FIRST, then
     * delete from the current run, so a crash mid-transfer never loses a
     * recipient (worst case: a duplicate queued row on the deferred run,
     * which firstOrCreate on the unique (run,contact) pair already prevents).
     *
     * @param Collection<int, CampaignRecipient> $surplus
     */
    private function deferSurplusToNextBusinessDay(CampaignRun $run, Collection $surplus, string $tz): void
    {
        if ($surplus->isEmpty()) {
            return;
        }

        $deferAt = $this->nextBusinessDaySameWallTime($run, $tz);
        $deferKey = preg_replace('/-d\d{8}$/', '', $run->occurrence_key) . '-d' . $deferAt->copy()->tz($tz)->format('Ymd');

        $deferredRun = CampaignRun::firstOrCreate(
            ['campaign_id' => $run->campaign_id, 'occurrence_key' => $deferKey],
            [
                'sequence_step_id' => $run->sequence_step_id,
                'run_at' => $deferAt,
                'status' => 'prepared',
                'driver_ref' => 'zoho-wave-pending',
            ],
        );

        foreach ($surplus as $recipient) {
            CampaignRecipient::firstOrCreate(
                ['campaign_run_id' => $deferredRun->id, 'contact_id' => $recipient->contact_id],
                ['status' => 'queued'],
            );
        }

        CampaignRecipient::query()->whereIn('id', $surplus->pluck('id'))->delete();

        SequenceEnrollment::query()
            ->where('campaign_id', $run->campaign_id)
            ->whereIn('contact_id', $surplus->pluck('contact_id'))
            ->where('status', 'active')
            ->update(['next_send_at' => $deferAt]);
    }

    public function send(CampaignRun $run): void
    {
        $run->refresh()->load(['campaign.senderIdentity', 'sequenceStep.template', 'recipients.contact.company']);
        if (config('services.zoho.driver', 'local') !== 'zoho'
            || ! in_array($run->campaign?->delivery_channel, [null, 'zoho'], true)) {
            return;
        }
        if (in_array($run->status, ['sent', 'failed', 'canceled'], true) || $this->isDeferred($run)) {
            return;
        }
        if ($this->hasPendingVerification($run)) {
            return;
        }

        if ($run->driver_ref === 'zoho-send-uncertain') {
            throw new \RuntimeException('Envoi Zoho incertain : reconciliation manuelle requise.');
        }

        $capOutcome = $this->enforceDailyCap($run);
        if ($capOutcome === 'deferred') {
            return;
        }
        if ($capOutcome === 'split') {
            // The gate deleted the surplus recipient rows directly in the DB;
            // $run's in-memory 'recipients' relation (loaded above) is stale.
            $run->load('recipients.contact.company');
        }

        $queuedBefore = $run->recipients->where('status', 'queued')->count();
        $contacts = $this->eligibleContacts($run);
        $sendAlreadyAttempted = $run->zoho_campaign_key && $run->driver_ref === 'zoho-send-attempted';
        if ($contacts->isEmpty() && ! $sendAlreadyAttempted) {
            $run->update([
                'status' => 'sent',
                'stats_sent' => 0,
                'driver_ref' => 'zoho-wave-empty',
                'finished_at' => now(),
                'failure_reason' => null,
            ]);
            return;
        }

        // Sequence waves send only to their own per-run synced list; never fall back
        // to the campaign/config list key. A missing key means sync never completed
        // (or was reset) — re-sync rather than dispatch against a wrong/empty list.
        if ($run->sequence_step_id !== null && ! $sendAlreadyAttempted && empty($run->zoho_list_key)) {
            $run->update([
                'status' => 'prepared',
                'zoho_campaign_key' => null,
                'driver_ref' => 'zoho-wave-pending',
            ]);
            SyncCampaignWaveZohoListJob::dispatch($run->id);
            return;
        }

        $audienceChanged = $contacts->count() !== $queuedBefore;
        // Broadened beyond the reused/synced happy path so a failed/pending run that
        // still carries a list key gets its membership re-verified too — with fix 1a,
        // an emptied Zoho list now round-trips as [] instead of throwing, so this is
        // what actually reaches the re-sync branch below instead of wedging forever.
        // Ambiguous states are excluded: zoho-send-uncertain throws above (:125-127)
        // and zoho-send-attempted is handled via $sendAlreadyAttempted.
        if (! $audienceChanged
            && in_array($run->driver_ref, ['zoho-wave-reused', 'zoho-wave-synced', 'zoho-wave-pending', 'zoho-wave-failed', 'zoho-send-failed'], true)
            && $run->zoho_list_key) {
            $normalize = fn ($emails): array => collect($emails)
                ->map(fn ($email): string => mb_strtolower(trim((string) $email)))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
            $audienceChanged = $normalize($contacts->pluck('email')) !== $normalize($this->listGateway->listEmails($run->zoho_list_key));
        }
        if ($audienceChanged && $run->zoho_list_key) {
            if (in_array($run->driver_ref, ['zoho-send-attempted', 'zoho-send-uncertain'], true)) {
                throw new \RuntimeException('Audience modifiee apres tentative Zoho : reconciliation manuelle requise.');
            }
            $run->update([
                'status' => 'prepared',
                'zoho_list_key' => null,
                'zoho_campaign_key' => null,
                'driver_ref' => 'zoho-wave-pending',
            ]);
            SyncCampaignWaveZohoListJob::dispatch($run->id);
            return;
        }

        $claimedRun = $this->deliveryFence->claimZohoTransport($run, ['prepared', 'scheduled', 'sending']);
        if ($claimedRun === null) {
            return;
        }
        $run = $claimedRun->load(['campaign.senderIdentity', 'sequenceStep.template', 'recipients.contact.company']);
        if ($this->hasPendingVerification($run)) {
            $run->update(['status' => 'scheduled', 'started_at' => null, 'finished_at' => null]);
            return;
        }
        $summary = $this->driver->dispatchRun($run, $contacts);
        $campaignKey = (string) $summary['campaign_key'];

        DB::transaction(function () use ($run, $contacts, $campaignKey): void {
            $locked = CampaignRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked->loadMissing('campaign');
            if ($locked->status === 'sent') {
                return;
            }

            $step = $locked->sequenceStep()->firstOrFail();
            $stepNo = (int) $step->step_no;
            $contactIds = $contacts->pluck('id');
            $enrollments = SequenceEnrollment::query()
                ->where('campaign_id', $locked->campaign_id)
                ->whereIn('contact_id', $contactIds)
                ->where('status', 'active')
                ->where('current_step', $stepNo - 1)
                ->lockForUpdate()
                ->get();

            // Zoho sends one campaign to the whole list — the provider id is per-RUN, not per-recipient,
            // and campaign_recipients.provider_message_id is globally unique. The key lives on
            // campaign_runs.zoho_campaign_key; derive it per recipient via campaign_run_id.
            CampaignRecipient::query()
                ->where('campaign_run_id', $locked->id)
                ->whereIn('contact_id', $enrollments->pluck('contact_id'))
                ->update(['status' => 'sent', 'sent_at' => now()]);

            foreach ($enrollments as $enrollment) {
                SequenceStepSend::updateOrCreate(
                    ['enrollment_id' => $enrollment->id, 'step_no' => $stepNo],
                    ['campaign_run_id' => $locked->id, 'provider_message_id' => $campaignKey, 'status' => 'sent', 'sent_at' => now()],
                );
            }

            $nextStep = $step->sequence->steps()->where('step_no', '>', $stepNo)->orderBy('step_no')->first();
            if ($nextStep === null) {
                SequenceEnrollment::whereKey($enrollments->pluck('id'))->update([
                    'current_step' => $stepNo,
                    'last_sent_at' => now(),
                    'status' => 'completed',
                    'next_send_at' => null,
                ]);
            } else {
                $baseKey = preg_replace('/-step-\d{3}$/', '', $locked->occurrence_key);
                $timezone = $this->calendar->resolveTimezone($locked->campaign);
                $clock = ($locked->campaign->next_run_at ?? $locked->run_at)
                    ->copy()
                    ->setTimezone($timezone);
                $nextRunAt = now($timezone)
                    ->addDays((int) $nextStep->delay_days)
                    ->setTime($clock->hour, $clock->minute, 0);
                $child = CampaignRun::firstOrCreate(
                    ['campaign_id' => $locked->campaign_id, 'occurrence_key' => $baseKey . '-step-' . str_pad((string) $nextStep->step_no, 3, '0', STR_PAD_LEFT)],
                    [
                        'sequence_step_id' => $nextStep->id,
                        'run_at' => $this->calendar->shiftToAllowed(
                            $nextRunAt,
                            $timezone,
                        ),
                        'status' => 'prepared',
                        'zoho_list_key' => $locked->zoho_list_key,
                        'driver_ref' => 'zoho-wave-reused',
                    ],
                );

                SequenceEnrollment::whereKey($enrollments->pluck('id'))->update([
                    'current_step' => $stepNo,
                    'last_sent_at' => now(),
                    // Track the child wave's actual scheduled dispatch time — never
                    // null — so a stalled wave pipeline stays visible instead of
                    // disappearing forever. See the matching comment in
                    // PacedSequenceEnrollmentService::evaluateDue().
                    'next_send_at' => $child->run_at,
                ]);

                foreach ($enrollments as $enrollment) {
                    CampaignRecipient::firstOrCreate(
                        ['campaign_run_id' => $child->id, 'contact_id' => $enrollment->contact_id],
                        ['status' => 'queued'],
                    );
                }
                $childId = $child->id;
                $runAt = $child->run_at;
                DB::afterCommit(fn () => SendSequenceWaveStepJob::dispatch($childId)->delay($runAt));
            }

            $locked->update([
                'status' => 'sent',
                'stats_sent' => $enrollments->count(),
                'finished_at' => now(),
                'driver_ref' => 'zoho',
            ]);
            $locked->campaign->markDeliveryStarted();
        }, 3);
    }

    public function recover(): int
    {
        if (config('services.zoho.driver', 'local') !== 'zoho') return 0;
        $adopted = $this->adoptLegacyEnrollments();
        $dispatched = 0;
        $runs = CampaignRun::query()
            ->whereNotNull('sequence_step_id')
            ->where('occurrence_key', 'like', 'sequence-wave-%')
            ->where('run_at', '<=', now())
            ->whereIn('status', ['prepared', 'scheduled', 'sending'])
            ->whereHas('campaign', fn ($query) => $query->where(fn ($channel) => $channel->whereNull('delivery_channel')->orWhere('delivery_channel', 'zoho')))
            ->get();

        foreach ($runs as $run) {
            if ($this->isDeferred($run)) {
                continue;
            }
            if ($run->status === 'prepared' && ! $run->zoho_list_key) {
                SyncCampaignWaveZohoListJob::dispatch($run->id);
            } else {
                SendSequenceWaveStepJob::dispatch($run->id);
            }
            $dispatched++;
        }

        return $adopted + $dispatched;
    }

    private function adoptLegacyEnrollments(): int
    {
        $enrollments = SequenceEnrollment::query()
            ->where('status', 'active')
            ->whereNotNull('next_send_at')
            ->whereHas('campaign', fn ($query) => $query
                ->where('schedule_type', 'sequence')
                ->where('sequence_enrollment_mode', 'paced')
                ->where(fn ($channel) => $channel->whereNull('delivery_channel')->orWhere('delivery_channel', 'zoho')))
            ->with(['campaign', 'sequence.steps', 'stepSends'])
            ->orderBy('id')
            ->get();

        // Pair each enrollment with its next step first — no query, both
        // checks rely on relations already eager-loaded above. Drops
        // enrollments with no further step, or an already-confirmed send
        // for it.
        $nextSteps = [];
        $candidates = $enrollments->filter(function (SequenceEnrollment $enrollment) use (&$nextSteps): bool {
            $nextStep = $enrollment->sequence->steps
                ->where('step_no', '>', (int) $enrollment->current_step)
                ->sortBy('step_no')
                ->first();

            if ($nextStep === null) {
                return false;
            }

            if ($enrollment->stepSends->contains(fn (SequenceStepSend $send): bool =>
                (int) $send->step_no === (int) $nextStep->step_no
                && ($send->provider_message_id !== null || in_array($send->status, ['sent', 'opened'], true)))) {
                return false;
            }

            $nextSteps[$enrollment->id] = $nextStep;

            return true;
        });

        // Since next_send_at is no longer nulled out for wave-managed
        // enrollments (PacedSequenceEnrollmentService::evaluateDue() /
        // this method / the step-advance branch above all now write a
        // real timestamp), whereNotNull('next_send_at') alone can no
        // longer distinguish "legacy, untracked" from "already tracked
        // by a normal sequence-wave-* run". Exclude any enrollment that
        // already has a recipient row for this exact step — adopting it
        // again would create a competing duplicate CampaignRun (and a
        // possible double-send) alongside the run already tracking it.
        // Prefetched as one whereIn query (composite contact/campaign/step
        // keys checked in memory) instead of one correlated exists() per
        // candidate — this runs over the unbounded legacy-recovery backlog.
        $trackedKeys = CampaignRecipient::query()
            ->whereIn('contact_id', $candidates->pluck('contact_id')->unique())
            ->whereHas('run', fn ($query) => $query
                ->whereIn('campaign_id', $candidates->pluck('campaign_id')->unique())
                ->whereIn('sequence_step_id', collect($nextSteps)->pluck('id')->unique()))
            ->with('run:id,campaign_id,sequence_step_id')
            ->get()
            ->map(fn (CampaignRecipient $recipient): string => implode('|', [
                $recipient->contact_id, $recipient->run->campaign_id, $recipient->run->sequence_step_id,
            ]))
            ->flip();

        $enrollments = $candidates->reject(fn (SequenceEnrollment $enrollment): bool => $trackedKeys->has(
            implode('|', [$enrollment->contact_id, $enrollment->campaign_id, $nextSteps[$enrollment->id]->id])
        ));

        $groups = $enrollments->groupBy(fn (SequenceEnrollment $enrollment): string => implode('|', [
            $enrollment->campaign_id,
            $enrollment->current_step,
            $enrollment->created_at->format('Ymd'),
        ]));
        $adopted = 0;

        foreach ($groups as $group) {
            $adopted += DB::transaction(function () use ($group): int {
                $first = $group->first();
                $step = $first->sequence->steps
                    ->where('step_no', '>', (int) $first->current_step)
                    ->sortBy('step_no')
                    ->first();
                $occurrenceKey = 'sequence-wave-legacy-'
                    . $first->created_at->format('Ymd')
                    . '-step-' . str_pad((string) $step->step_no, 3, '0', STR_PAD_LEFT);
                $runAt = $this->calendar->shiftToAllowed(
                    $group->sortBy('next_send_at')->first()->next_send_at,
                    $this->calendar->resolveTimezone($first->campaign),
                );
                $run = CampaignRun::firstOrCreate(
                    ['campaign_id' => $first->campaign_id, 'occurrence_key' => $occurrenceKey],
                    ['sequence_step_id' => $step->id, 'run_at' => $runAt, 'status' => 'prepared', 'driver_ref' => 'zoho-wave-pending'],
                );
                $count = 0;

                foreach ($group as $candidate) {
                    $enrollment = SequenceEnrollment::query()->lockForUpdate()->find($candidate->id);
                    if ($enrollment === null || $enrollment->status !== 'active' || $enrollment->next_send_at === null) {
                        continue;
                    }
                    $confirmed = SequenceStepSend::where('enrollment_id', $enrollment->id)
                        ->where('step_no', $step->step_no)
                        ->where(fn ($query) => $query->whereNotNull('provider_message_id')->orWhereIn('status', ['sent', 'opened']))
                        ->exists();
                    if ($confirmed) {
                        continue;
                    }
                    CampaignRecipient::firstOrCreate(
                        ['campaign_run_id' => $run->id, 'contact_id' => $enrollment->contact_id],
                        ['status' => 'queued'],
                    );
                    // Track the adoption run's own scheduled dispatch time — never
                    // null — matching PacedSequenceEnrollmentService::evaluateDue().
                    $enrollment->update(['next_send_at' => $runAt]);
                    $count++;
                }

                if ($count > 0) {
                    $runId = $run->id;
                    DB::afterCommit(fn () => SyncCampaignWaveZohoListJob::dispatch($runId)->delay($runAt));
                }

                return $count;
            }, 3);
        }

        return $adopted;
    }
}
