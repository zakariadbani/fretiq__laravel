<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SenderIdentity;
use App\Models\SmtpSendReservation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SmtpSendReservationService
{
    /** @return array{ok:bool,reservation:?SmtpSendReservation,send_at:?Carbon,reason:?string} */
    public function reserve(
        SenderIdentity $identity,
        Campaign $campaign,
        string $sourceType,
        int $sourceId,
        ?CarbonInterface $now = null,
    ): array {
        $existingSnapshot = SmtpSendReservation::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($campaign->smtpDailyEmailLimit() === 0) {
            return ['ok' => false, 'reservation' => null, 'send_at' => null, 'reason' => 'campaign_paused'];
        }

        return DB::transaction(function () use ($identity, $campaign, $sourceType, $sourceId, $now, $existingSnapshot): array {
            // A legacy reservation can point at an older sender. Lock all known
            // identities first, deterministically, before touching its row.
            $identityIds = collect([$identity->id, $existingSnapshot?->sender_identity_id])
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort();
            foreach ($identityIds as $identityId) {
                SenderIdentity::query()->lockForUpdate()->findOrFail($identityId);
            }
            $lockedIdentity = SenderIdentity::query()->findOrFail($identity->id);

            $recipientSnapshot = $sourceType === SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT
                ? CampaignRecipient::query()->find($sourceId)
                : null;

            $existing = SmtpSendReservation::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! $lockedCampaign->is_active || $lockedCampaign->smtpDailyEmailLimit() === 0) {
                return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'campaign_paused'];
            }

            if ($sourceType === SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT) {
                if ($recipientSnapshot === null) {
                    return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'source_unavailable'];
                }

                $lockedRun = CampaignRun::query()->lockForUpdate()->find($recipientSnapshot->campaign_run_id);
                $lockedRecipient = CampaignRecipient::query()->lockForUpdate()->find($sourceId);
                if ($lockedRun === null || $lockedRecipient === null
                    || (int) $lockedRun->campaign_id !== (int) $lockedCampaign->id
                    || (int) $lockedRecipient->campaign_run_id !== (int) $lockedRun->id
                    || ($existing !== null && (int) $existing->campaign_id !== (int) $lockedCampaign->id)) {
                    return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'source_unavailable'];
                }
                if ($lockedRun->status === 'canceled') {
                    return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'run_canceled'];
                }
                if (! in_array($lockedRun->status, ['scheduled', 'sending'], true)
                    || $lockedRecipient->status !== 'queued'
                    || filled($lockedRecipient->provider_message_id)
                    || $lockedRecipient->sent_at !== null) {
                    return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'source_unavailable'];
                }
            }

            // Even an existing active row is returned only after the campaign
            // recipient source has been revalidated under the lifecycle locks.
            if ($existing !== null
                && in_array($existing->status, ['released', 'failed'], true)
                && ! $existing->isReusableBeforeTransport()) {
                return ['ok' => false, 'reservation' => $existing, 'send_at' => null, 'reason' => 'transport_evidence'];
            }

            if ($existing !== null && ! $existing->isReusableBeforeTransport()) {
                return $this->result($existing);
            }

            $candidate = $this->nextSlot($lockedIdentity, $lockedCampaign, $now);
            if ($existing !== null) {
                $existing->update([
                    'sender_identity_id' => $lockedIdentity->id,
                    'campaign_id' => $lockedCampaign->id,
                    'reserved_for' => $candidate->copy()->utc(),
                    'status' => 'reserved', 'attempted_at' => null, 'accepted_at' => null,
                    'sent_at' => null, 'provider_message_id' => null, 'lease_expires_at' => null, 'attempt_count' => 0,
                ]);
                return $this->result($existing->fresh());
            }
            $reservation = SmtpSendReservation::create([
                'sender_identity_id' => $lockedIdentity->id,
                'campaign_id' => $lockedCampaign->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'reserved_for' => $candidate->copy()->utc(),
                'status' => 'reserved',
            ]);

            return $this->result($reservation);
        }, 3);
    }

    /**
     * Atomically recheck, re-slot and claim under the sender identity lock.
     *
     * @return array{ok:bool,reservation:SmtpSendReservation,send_at:?CarbonInterface,reason:?string}
     */
    public function claimWhenDue(SmtpSendReservation $reservation, ?CarbonInterface $now = null): array
    {
        return DB::transaction(function () use ($reservation, $now): array {
            // Lock order is deliberately identity -> reservation -> campaign in
            // every execution path, serializing one sender's transport decisions.
            $snapshot = SmtpSendReservation::findOrFail($reservation->id);
            $identity = SenderIdentity::query()->lockForUpdate()->findOrFail($snapshot->sender_identity_id);
            $fresh = SmtpSendReservation::with(['campaign', 'senderIdentity'])->lockForUpdate()->findOrFail($reservation->id);
            if ((int) $fresh->sender_identity_id !== (int) $identity->id) {
                return ['ok' => false, 'reservation' => $fresh, 'send_at' => null, 'reason' => 'identity_changed'];
            }
            $campaign = Campaign::query()->findOrFail($fresh->campaign_id);
            $nowUtc = Carbon::instance(($now ?? now())->toDateTime())->utc();
            if ($fresh->status !== 'reserved') {
                return ['ok' => false, 'reservation' => $fresh, 'send_at' => null, 'reason' => 'not_reserved'];
            }
            if (! $campaign->is_active || $campaign->smtpDailyEmailLimit() === 0) {
                $fresh->update(['status' => 'released', 'lease_expires_at' => null]);
                return ['ok' => false, 'reservation' => $fresh->fresh(), 'send_at' => null, 'reason' => 'campaign_paused'];
            }
            if ($fresh->reserved_for->gt($nowUtc)) {
                return ['ok' => false, 'reservation' => $fresh, 'send_at' => $fresh->reserved_for, 'reason' => 'not_due'];
            }

            // Queue workers are not guaranteed to start in reservation order.
            // Do not let a later overdue row leapfrog an earlier eligible row
            // for the same mailbox after downtime.
            $earlierPending = SmtpSendReservation::query()
                ->where('sender_identity_id', $identity->id)
                ->where('status', 'reserved')
                ->where('reserved_for', '<=', $nowUtc)
                ->where(function ($query) use ($fresh): void {
                    $query->where('reserved_for', '<', $fresh->reserved_for)
                        ->orWhere(function ($sameSlot) use ($fresh): void {
                            $sameSlot->where('reserved_for', $fresh->reserved_for)
                                ->where('id', '<', $fresh->id);
                        });
                })
                ->whereHas('campaign', function ($query): void {
                    $query->where('is_active', true)
                        ->where('delivery_channel', 'smtp')
                        ->where(function ($limit): void {
                            $limit->whereNull('smtp_daily_email_limit')
                                ->orWhere('smtp_daily_email_limit', '>', 0);
                        });
                })
                ->exists();
            if ($earlierPending) {
                return [
                    'ok' => false,
                    'reservation' => $fresh,
                    'send_at' => $nowUtc->copy()->addMinute(),
                    'reason' => 'waiting_turn',
                ];
            }

            // Re-slot at execution time under the current limits. Excluding the
            // current row lets a lowered limit defer it without double counting.
            $other = SmtpSendReservation::query()->where('id', '!=', $fresh->id)
                ->where('sender_identity_id', $identity->id)->whereIn('status', ['sending', 'accepted', 'sent', 'uncertain'])
                ->where('reserved_for', '<=', $nowUtc);
            $due = $this->nextSlot($identity, $campaign, $now, $other, $fresh->id, true);
            if ($due->gt($nowUtc)) {
                $fresh->update(['reserved_for' => $due, 'status' => 'reserved']);
                return ['ok' => false, 'reservation' => $fresh->fresh(), 'send_at' => $due, 'reason' => 'not_due'];
            }
            SmtpSendReservation::query()->whereKey($fresh->id)->update([
                'status' => 'sending',
                'reserved_for' => $nowUtc,
                'attempted_at' => $nowUtc,
                'lease_expires_at' => $nowUtc->copy()->addMinutes(5),
                'attempt_count' => DB::raw('attempt_count + 1'),
            ]);
            return ['ok' => true, 'reservation' => $fresh->fresh(), 'send_at' => $fresh->reserved_for, 'reason' => null];
        }, 3);
    }

    public function defer(SmtpSendReservation $reservation, CarbonInterface $until): void
    {
        SmtpSendReservation::query()->whereKey($reservation->id)->update([
            'status' => 'reserved',
            'reserved_for' => Carbon::instance($until->toDateTime())->utc(),
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Compute, but do not reserve, the earliest currently safe SMTP slot.
     * Lifecycle callers should hold the sender identity and campaign locks in
     * that order. The send worker will revalidate all limits when it eventually
     * materializes and claims a reservation.
     */
    public function earliestSafeSlot(
        SenderIdentity $identity,
        Campaign $campaign,
        ?CarbonInterface $notBefore = null,
    ): Carbon {
        if ($campaign->smtpDailyEmailLimit() === 0) {
            throw new \InvalidArgumentException('La campagne est en pause pour les envois SMTP.');
        }

        // Timeline timestamps are persisted and exposed in UTC. nextSlot()
        // calculates in the campaign wall-clock timezone, so normalize at this
        // public boundary before callers update a run or build an API response.
        return $this->nextSlot($identity, $campaign, $notBefore, null, null, false, true)->utc();
    }

    /**
     * Move one still-unattempted campaign reservation into the earliest safe
     * gap. Callers that coordinate a campaign run must already hold the
     * identity, reservation and campaign locks in that order.
     *
     * @return array{ok:bool,reservation:SmtpSendReservation,send_at:Carbon,reason:null}
     */
    public function moveToEarliestSafeSlot(
        SmtpSendReservation $reservation,
        SenderIdentity $identity,
        Campaign $campaign,
        ?CarbonInterface $now = null,
    ): array {
        if ($reservation->status !== 'reserved') {
            throw new \InvalidArgumentException('La réservation SMTP n’est plus disponible.');
        }

        $candidate = $this->nextSlot($identity, $campaign, $now, null, $reservation->id, false, true);
        $reservation->update([
            'reserved_for' => $candidate->copy()->utc(),
            'lease_expires_at' => null,
        ]);

        return $this->result($reservation->fresh());
    }

    public function markSent(SmtpSendReservation $reservation): void
    {
        SmtpSendReservation::query()->whereKey($reservation->id)->update([
            'status' => 'sent',
            'sent_at' => now(), 'lease_expires_at' => null,
        ]);
    }

    public function markUncertainAfterTransport(SmtpSendReservation $reservation): void
    {
        SmtpSendReservation::query()->whereKey($reservation->id)->update([
            'status' => 'uncertain',
            'lease_expires_at' => null,
        ]);
    }

    public function markFailed(SmtpSendReservation $reservation): void
    {
        SmtpSendReservation::query()->whereKey($reservation->id)->update(['status' => 'failed', 'lease_expires_at' => null]);
    }

    public function release(SmtpSendReservation $reservation): void
    {
        SmtpSendReservation::query()
            ->whereKey($reservation->id)
            ->whereIn('status', ['reserved', 'sending'])
            ->whereNull('provider_message_id')
            ->whereNull('accepted_at')
            ->whereNull('sent_at')
            ->update(['status' => 'released', 'lease_expires_at' => null]);
    }

    private function nextSlot(
        SenderIdentity $identity,
        Campaign $campaign,
        ?CarbonInterface $now,
        $activeOverride = null,
        ?int $excludeId = null,
        bool $execution = false,
        bool $findEarliest = false,
    ): Carbon
    {
        $quotaTz = (string) config('prospecting.smtp.quota_timezone', 'Europe/Paris');
        $campaignTz = $campaign->scheduleTimezone();
        $base = Carbon::instance(($now ?? now())->toDateTime())->setTimezone($campaignTz);
        $candidate = $this->withinBusinessWindow($base, $campaign);
        $active = $activeOverride ?? SmtpSendReservation::query()
            ->where('sender_identity_id', $identity->id)
            ->where(function ($statuses): void {
                $statuses->whereIn('status', ['sending', 'accepted', 'sent', 'uncertain'])
                    ->orWhere(function ($reserved): void {
                        $reserved->where('status', 'reserved')
                            ->whereHas('campaign', function ($campaign): void {
                                $campaign->where('is_active', true)
                                    ->where('delivery_channel', 'smtp')
                                    ->where(function ($limit): void {
                                        $limit->whereNull('smtp_daily_email_limit')
                                            ->orWhere('smtp_daily_email_limit', '>', 0);
                                    });
                            });
                    });
            });
        if ($excludeId !== null && $activeOverride === null) {
            $active->where('id', '!=', $excludeId);
        }

        $window = $this->window($campaign);
        $windowSeconds = max(60, ($window['end_minutes'] - $window['start_minutes']) * 60);
        $hourlySpacing = (int) ceil(3600 / max(1, (int) $identity->smtp_hourly_limit));
        $dailyLimit = min(max(1, (int) $identity->smtp_daily_limit), $campaign->smtpDailyEmailLimit());
        $dailySpacing = (int) ceil($windowSeconds / max(1, $dailyLimit));
        $spacing = max($hourlySpacing, $dailySpacing);

        $latestReservedFor = ! $findEarliest ? (clone $active)->max('reserved_for') : null;
        if ($latestReservedFor !== null) {
            $latest = Carbon::parse((string) $latestReservedFor, 'UTC')->setTimezone($campaignTz);
            $candidate = $candidate->max($latest->addSeconds($spacing));
            $candidate = $this->withinBusinessWindow($candidate, $campaign);
        }

        for ($attempt = 0; $attempt < 2000; $attempt++) {
            $quotaCandidate = $candidate->copy()->setTimezone($quotaTz);
            $dayStart = $quotaCandidate->copy()->startOfDay()->utc();
            $dayEnd = $quotaCandidate->copy()->endOfDay()->utc();
            $hourStart = $quotaCandidate->copy()->startOfHour()->utc();
            $hourEnd = $quotaCandidate->copy()->endOfHour()->utc();

            $identityDay = (clone $active)->whereBetween('reserved_for', [$dayStart, $dayEnd])->count();
            $identityHour = (clone $active)->whereBetween('reserved_for', [$hourStart, $hourEnd])->count();
            $campaignDayQuery = SmtpSendReservation::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', $execution ? ['sending', 'accepted', 'sent', 'uncertain'] : SmtpSendReservation::ACTIVE_STATUSES)
                ->whereBetween('reserved_for', [$dayStart, $dayEnd]);
            if ($excludeId !== null) $campaignDayQuery->where('id', '!=', $excludeId);
            $campaignDay = $campaignDayQuery->count();

            if ($identityDay >= (int) $identity->smtp_daily_limit || $campaignDay >= $campaign->smtpDailyEmailLimit()) {
                $candidate = $this->withinBusinessWindow($candidate->copy()->addDay()->startOfDay(), $campaign);
                continue;
            }

            if ($identityHour >= (int) $identity->smtp_hourly_limit) {
                $candidate = $this->withinBusinessWindow($candidate->copy()->addHour()->startOfHour(), $campaign);
                continue;
            }

            if ($findEarliest) {
                $previous = (clone $active)
                    ->where('reserved_for', '<=', $candidate->copy()->utc())
                    ->orderByDesc('reserved_for')
                    ->orderByDesc('id')
                    ->first(['reserved_for']);
                if ($previous !== null) {
                    $afterPrevious = Carbon::parse((string) $previous->reserved_for, 'UTC')
                        ->setTimezone($campaignTz)
                        ->addSeconds($spacing);
                    if ($afterPrevious->gt($candidate)) {
                        $candidate = $this->withinBusinessWindow($afterPrevious, $campaign);
                        continue;
                    }
                }

                $following = (clone $active)
                    ->where('reserved_for', '>', $candidate->copy()->utc())
                    ->orderBy('reserved_for')
                    ->orderBy('id')
                    ->first(['reserved_for']);
                if ($following !== null) {
                    $beforeFollowing = Carbon::parse((string) $following->reserved_for, 'UTC')
                        ->setTimezone($campaignTz)
                        ->subSeconds($spacing);
                    if ($candidate->gt($beforeFollowing)) {
                        $candidate = $this->withinBusinessWindow(
                            Carbon::parse((string) $following->reserved_for, 'UTC')
                                ->setTimezone($campaignTz)
                                ->addSeconds($spacing),
                            $campaign,
                        );
                        continue;
                    }
                }
            }

            $collision = (clone $active)
                ->where('reserved_for', $candidate->copy()->utc())
                ->exists();
            if (! $collision) {
                return $candidate;
            }

            $candidate = $this->withinBusinessWindow($candidate->copy()->addSeconds($spacing), $campaign);
        }

        throw new \RuntimeException('Aucun créneau SMTP sûr disponible.');
    }

    private function withinBusinessWindow(Carbon $candidate, Campaign $campaign): Carbon
    {
        $window = $this->window($campaign);
        for ($guard = 0; $guard < 370; $guard++) {
            if (! in_array($candidate->dayOfWeekIso, $window['days'], true)) {
                $candidate->addDay()->startOfDay();
                continue;
            }

            $start = $candidate->copy()->startOfDay()->addMinutes($window['start_minutes']);
            $end = $candidate->copy()->startOfDay()->addMinutes($window['end_minutes']);
            if ($candidate->lt($start)) {
                return $start;
            }
            if ($candidate->gte($end)) {
                $candidate->addDay()->startOfDay();
                continue;
            }

            return $candidate;
        }

        throw new \RuntimeException('Fenêtre SMTP invalide.');
    }

    /** @return array{days:array<int,int>,start_minutes:int,end_minutes:int} */
    private function window(Campaign $campaign): array
    {
        $configured = $campaign->send_window ?? [];
        $days = array_values(array_intersect(
            array_map('intval', $configured['days'] ?? [1, 2, 3, 4, 5]),
            [1, 2, 3, 4, 5],
        ));
        $days = $days === [] ? [1, 2, 3, 4, 5] : $days;
        $start = (string) ($configured['start'] ?? config('prospecting.smtp.business_start', '09:00'));
        $end = (string) ($configured['end'] ?? config('prospecting.smtp.business_end', '18:00'));

        return [
            'days' => $days,
            'start_minutes' => $this->minutes($start, 9 * 60),
            'end_minutes' => $this->minutes($end, 18 * 60),
        ];
    }

    private function minutes(string $time, int $fallback): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return $fallback;
        }

        return min(1439, max(0, ((int) $matches[1] * 60) + (int) $matches[2]));
    }

    /** @return array{ok:bool,reservation:SmtpSendReservation,send_at:Carbon,reason:?string} */
    private function result(SmtpSendReservation $reservation): array
    {
        return [
            'ok' => true,
            'reservation' => $reservation,
            'send_at' => $reservation->reserved_for,
            'reason' => null,
        ];
    }
}
