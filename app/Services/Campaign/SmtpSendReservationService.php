<?php

namespace App\Services\Campaign;

use App\Models\Campaign;
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
        $existing = SmtpSendReservation::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing !== null && ! in_array($existing->status, ['released', 'failed'], true)) {
            return $this->result($existing);
        }

        if ($campaign->smtpDailyEmailLimit() === 0) {
            return ['ok' => false, 'reservation' => null, 'send_at' => null, 'reason' => 'campaign_paused'];
        }

        return DB::transaction(function () use ($identity, $campaign, $sourceType, $sourceId, $now): array {
            $lockedIdentity = SenderIdentity::query()->lockForUpdate()->findOrFail($identity->id);
            $lockedCampaign = Campaign::query()->findOrFail($campaign->id);

            $existing = SmtpSendReservation::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing !== null && ! in_array($existing->status, ['released', 'failed'], true)) {
                return $this->result($existing);
            }

            if ($lockedCampaign->smtpDailyEmailLimit() === 0) {
                return ['ok' => false, 'reservation' => null, 'send_at' => null, 'reason' => 'campaign_paused'];
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
        SmtpSendReservation::query()->whereKey($reservation->id)->update(['status' => 'released', 'lease_expires_at' => null]);
    }

    private function nextSlot(
        SenderIdentity $identity,
        Campaign $campaign,
        ?CarbonInterface $now,
        $activeOverride = null,
        ?int $excludeId = null,
        bool $execution = false,
    ): Carbon
    {
        $quotaTz = (string) config('prospecting.smtp.quota_timezone', 'Europe/Paris');
        $campaignTz = $campaign->scheduleTimezone();
        $base = Carbon::instance(($now ?? now())->toDateTime())->setTimezone($campaignTz);
        $candidate = $this->withinBusinessWindow($base, $campaign);
        $active = $activeOverride ?: SmtpSendReservation::query()
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

        $window = $this->window($campaign);
        $windowSeconds = max(60, ($window['end_minutes'] - $window['start_minutes']) * 60);
        $hourlySpacing = (int) ceil(3600 / max(1, (int) $identity->smtp_hourly_limit));
        $dailyLimit = min(max(1, (int) $identity->smtp_daily_limit), $campaign->smtpDailyEmailLimit());
        $dailySpacing = (int) ceil($windowSeconds / max(1, $dailyLimit));
        $spacing = max($hourlySpacing, $dailySpacing);

        $latestReservedFor = (clone $active)->max('reserved_for');
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
