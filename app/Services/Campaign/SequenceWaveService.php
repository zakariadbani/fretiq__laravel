<?php

declare(strict_types=1);

namespace App\Services\Campaign;

use App\Jobs\SendSequenceWaveStepJob;
use App\Jobs\SyncCampaignWaveZohoListJob;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepSend;
use App\Models\Suppression;
use App\Services\Scheduling\BusinessCalendarService;
use App\Services\Zoho\ZohoRecipientListGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SequenceWaveService
{
    public function __construct(
        private readonly ZohoCampaignsDriver $driver,
        private readonly BusinessCalendarService $calendar,
        private readonly ZohoRecipientListGateway $listGateway,
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
    /** @return Collection<int, \App\Models\Contact> */
    public function eligibleContacts(CampaignRun $run): Collection
    {
        $run->loadMissing(['sequenceStep', 'recipients.contact.company']);
        if ($run->sequenceStep === null) {
            return $run->recipients->where('status', 'queued')->pluck('contact')->filter()->values();
        }
        $stepNo = (int) $run->sequenceStep->step_no;
        $contacts = collect();

        foreach ($run->recipients->where('status', 'queued') as $recipient) {
            $enrollment = SequenceEnrollment::query()
                ->where('campaign_id', $run->campaign_id)
                ->where('contact_id', $recipient->contact_id)
                ->first();
            if ($enrollment?->status === 'paused') {
                continue;
            }
            $suppressed = $recipient->contact === null
                || Suppression::isSuppressed((string) $recipient->contact->email);
            $eligible = $enrollment !== null
                && $enrollment->status === 'active'
                && (int) $enrollment->current_step === $stepNo - 1
                && ! $suppressed;

            if ($eligible) {
                $contacts->push($recipient->contact);
                continue;
            }

            $reason = $suppressed ? 'suppressed' : 'enrollment_ineligible';
            $recipient->update(['status' => 'skipped', 'skip_reason' => $reason]);
            if ($enrollment !== null) {
                SequenceStepSend::updateOrCreate(
                    ['enrollment_id' => $enrollment->id, 'step_no' => $stepNo],
                    ['campaign_run_id' => $run->id, 'status' => 'skipped'],
                );
                if ($suppressed && $enrollment->status === 'active') {
                    $enrollment->update(['status' => 'stopped', 'stopped_reason' => 'suppressed', 'next_send_at' => null]);
                }
            }
        }

        return $contacts;
    }

    public function send(CampaignRun $run): void
    {
        $run->refresh()->load(['campaign.senderIdentity', 'sequenceStep.template', 'recipients.contact.company']);
        if (in_array($run->status, ['sent', 'failed', 'canceled'], true) || $this->isDeferred($run)) {
            return;
        }

        if ($run->driver_ref === 'zoho-send-uncertain') {
            throw new \RuntimeException('Envoi Zoho incertain : reconciliation manuelle requise.');
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

        $audienceChanged = $contacts->count() !== $queuedBefore;
        if (! $audienceChanged && $run->driver_ref === 'zoho-wave-reused' && $run->zoho_list_key) {
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

        $run->update(['status' => 'sending', 'started_at' => $run->started_at ?? now(), 'failure_reason' => null]);
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
                SequenceEnrollment::whereKey($enrollments->pluck('id'))->update([
                    'current_step' => $stepNo,
                    'last_sent_at' => now(),
                    'next_send_at' => null,
                ]);
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
        }, 3);
    }

    public function recover(): int
    {
        $adopted = $this->adoptLegacyEnrollments();
        $dispatched = 0;
        $runs = CampaignRun::query()
            ->whereNotNull('sequence_step_id')
            ->where('occurrence_key', 'like', 'sequence-wave-%')
            ->where('run_at', '<=', now())
            ->whereIn('status', ['prepared', 'scheduled', 'sending'])
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
                ->where('sequence_enrollment_mode', 'paced'))
            ->with(['campaign', 'sequence.steps', 'stepSends'])
            ->orderBy('id')
            ->get()
            ->filter(function (SequenceEnrollment $enrollment): bool {
                $nextStep = $enrollment->sequence->steps
                    ->where('step_no', '>', (int) $enrollment->current_step)
                    ->sortBy('step_no')
                    ->first();

                return $nextStep !== null
                    && ! $enrollment->stepSends->contains(fn (SequenceStepSend $send): bool =>
                        (int) $send->step_no === (int) $nextStep->step_no
                        && ($send->provider_message_id !== null || in_array($send->status, ['sent', 'opened'], true)));
            });

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
                    $enrollment->update(['next_send_at' => null]);
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