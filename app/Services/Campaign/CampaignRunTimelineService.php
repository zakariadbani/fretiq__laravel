<?php

namespace App\Services\Campaign;

use App\Jobs\SendCampaignJob;
use App\Jobs\SendSmtpReservationJob;
use App\Models\Campaign;
use App\Models\CampaignCompanyDispatch;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\SenderIdentity;
use App\Models\SmtpSendReservation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CampaignRunTimelineService
{
    public function __construct(
        private readonly CampaignSchedulerService $scheduler,
        private readonly SmtpSendReservationService $smtpReservations,
        private readonly CampaignService $campaigns,
    ) {}

    /**
     * Suspend future SMTP work without touching a transport attempt that may already
     * have reached a mailbox provider.
     */
    public function pause(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $reservationSnapshots = SmtpSendReservation::query()
                ->select([
                    'smtp_send_reservations.id',
                    'smtp_send_reservations.sender_identity_id',
                ])
                ->join('campaign_recipients', function ($join): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT);
                })
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_recipients.campaign_run_id')
                ->where('campaign_runs.campaign_id', $campaign->id)
                ->whereIn('campaign_runs.status', ['prepared', 'scheduled', 'sending'])
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->orderBy('smtp_send_reservations.id')
                ->get()
                ->groupBy('sender_identity_id');

            // Match SMTP execution's identity -> reservation -> campaign order.
            // Sorting identities also keeps a campaign with legacy mixed identities
            // deterministic while it holds more than one sender lock.
            $senderIdentityIds = $reservationSnapshots->keys()
                ->push($campaign->sender_identity_id)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort();

            foreach ($senderIdentityIds as $senderIdentityId) {
                SenderIdentity::query()->lockForUpdate()->findOrFail($senderIdentityId);
            }

            // The first read establishes legacy identity locks only. Re-read after
            // those locks are held so a reservation created before the current
            // sender was locked is also paused.
            $reservationIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT);
                })
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_recipients.campaign_run_id')
                ->where('campaign_runs.campaign_id', $campaign->id)
                ->whereIn('campaign_runs.status', ['prepared', 'scheduled', 'sending'])
                ->pluck('smtp_send_reservations.id');
            $reservations = SmtpSendReservation::query()
                ->whereIn('id', $reservationIds)
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->orderBy('smtp_send_reservations.id')
                ->lockForUpdate()
                ->get();

            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $runs = CampaignRun::query()
                ->where('campaign_id', $lockedCampaign->id)
                ->whereIn('status', ['prepared', 'scheduled', 'sending'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $recipients = CampaignRecipient::query()
                ->whereIn('campaign_run_id', $runs->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('campaign_run_id');

            $lockedCampaign->update(['is_active' => false]);

            // Only a reservation which has not crossed the transport boundary is
            // safely releasable. The locked collection makes this status check stable.
            $reservations->where('status', 'reserved')->each(function (SmtpSendReservation $reservation): void {
                $reservation->update(['status' => 'released', 'lease_expires_at' => null]);
            });

            $reservationsByRecipient = $reservations
                ->keyBy(fn (SmtpSendReservation $reservation): string => $reservation->source_type . ':' . $reservation->source_id);

            foreach ($runs as $run) {
                if ($run->status !== 'sending') {
                    continue;
                }

                $runRecipients = $recipients->get($run->id, collect());
                $runReservations = $runRecipients
                    ->map(fn (CampaignRecipient $recipient) => $reservationsByRecipient->get(
                        SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT . ':' . $recipient->id,
                    ))
                    ->filter();
                $allRecipientsQueued = $runRecipients->every(
                    fn (CampaignRecipient $recipient): bool => $recipient->status === 'queued',
                );
                $hasTransportEvidence = $runRecipients->contains(
                    fn (CampaignRecipient $recipient): bool => filled($recipient->provider_message_id) || $recipient->sent_at !== null,
                ) || $runReservations->contains(
                    fn (SmtpSendReservation $reservation): bool => in_array($reservation->status, ['sending', 'accepted', 'uncertain', 'sent'], true)
                        || filled($reservation->provider_message_id)
                        || $reservation->accepted_at !== null
                        || $reservation->sent_at !== null,
                );
                $hasRunEvidence = $this->hasRunTransportEvidence($run);
                $provenSafeSmtp = $lockedCampaign->delivery_channel === 'smtp'
                    && $run->driver_ref === 'smtp'
                    && ! $hasRunEvidence
                    && $runRecipients->isNotEmpty()
                    && $runReservations->isNotEmpty()
                    && $allRecipientsQueued
                    && ! $hasTransportEvidence;

                if ($provenSafeSmtp) {
                    $run->update(['status' => 'scheduled', 'started_at' => null]);
                }
            }
        }, 3);
    }

    /**
     * Reactivate a campaign without dispatching work immediately. A paused, stale
     * occurrence is moved to the next valid slot before the scheduler may see it.
     */
    public function resume(Campaign $campaign, CarbonInterface $now): CarbonInterface
    {
        return DB::transaction(function () use ($campaign, $now): CarbonInterface {
            $clock = Carbon::instance($now);

            // Match transport's identity -> reservation -> campaign -> run ->
            // recipient order. Include legacy reservation identities and sort
            // the locks so mixed-identity history stays deterministic.
            $identityIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT);
                })
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_recipients.campaign_run_id')
                ->where('campaign_runs.campaign_id', $campaign->id)
                ->whereIn('campaign_runs.status', ['prepared', 'scheduled', 'sending'])
                ->pluck('smtp_send_reservations.sender_identity_id')
                ->push($campaign->sender_identity_id)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort();
            foreach ($identityIds as $identityId) {
                SenderIdentity::query()->lockForUpdate()->findOrFail($identityId);
            }

            $reservationIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT);
                })
                ->join('campaign_runs', 'campaign_runs.id', '=', 'campaign_recipients.campaign_run_id')
                ->where('campaign_runs.campaign_id', $campaign->id)
                ->whereIn('campaign_runs.status', ['prepared', 'scheduled', 'sending'])
                ->pluck('smtp_send_reservations.id');
            $reservationRows = SmtpSendReservation::query()
                ->whereIn('id', $reservationIds)
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->orderBy('smtp_send_reservations.id')
                ->lockForUpdate()
                ->get();
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $runs = CampaignRun::query()
                ->where('campaign_id', $lockedCampaign->id)
                ->whereIn('status', ['prepared', 'scheduled', 'sending'])
                ->orderBy('run_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $recipients = CampaignRecipient::query()
                ->whereIn('campaign_run_id', $runs->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('campaign_run_id');
            $reservations = $reservationRows
                ->groupBy(fn (SmtpSendReservation $reservation): string => $reservation->source_type . ':' . $reservation->source_id);

            $pendingAt = null;

            if ($lockedCampaign->schedule_type === 'one_shot') {
                foreach ($runs as $run) {
                    if ($run->status !== 'scheduled' || $run->run_at === null) {
                        continue;
                    }

                    $runRecipients = $recipients->get($run->id, collect());
                    $hasTransportEvidence = $this->hasRunTransportEvidence($run)
                        || $runRecipients->contains(function (CampaignRecipient $recipient) use ($reservations): bool {
                            $recipientReservations = $reservations->get(
                                SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT . ':' . $recipient->id,
                                collect(),
                            );

                            return filled($recipient->provider_message_id)
                                || $recipient->sent_at !== null
                                || $recipientReservations->contains(
                                    fn (SmtpSendReservation $reservation): bool => in_array($reservation->status, ['sending', 'accepted', 'uncertain', 'sent'], true)
                                        || $reservation->hasProviderTransportEvidence(),
                                );
                        });
                    $isUntouched = $runRecipients->isEmpty()
                        || $runRecipients->every(
                            fn (CampaignRecipient $recipient): bool => $recipient->status === 'queued',
                        );

                    if ($hasTransportEvidence || ! $isUntouched) {
                        throw new InvalidArgumentException('Un lot planifié contient déjà une tentative d’envoi. Vérifiez l’historique avant de reprendre la campagne.');
                    }

                    if ($run->run_at->lte($clock)) {
                        $safeFloor = $clock->copy()->addMinutes(5);
                        $nextRunAt = $safeFloor;
                        if ($lockedCampaign->delivery_channel === 'smtp') {
                            if ($lockedCampaign->sender_identity_id === null) {
                                throw new InvalidArgumentException('Sélectionnez un expéditeur avant de reprendre la campagne.');
                            }
                            $identity = SenderIdentity::query()->findOrFail($lockedCampaign->sender_identity_id);
                            $nextRunAt = $this->smtpReservations->earliestSafeSlot($identity, $lockedCampaign, $safeFloor);
                        }
                        $run->update(['run_at' => $nextRunAt]);
                    }

                    $pendingAt = $this->later($pendingAt, $run->run_at);
                }
            }

            if (in_array($lockedCampaign->schedule_type, ['paced', 'recurring'], true)) {
                foreach ($runs as $run) {
                    if ($run->status !== 'scheduled' || $run->run_at === null) {
                        continue;
                    }

                    $runRecipients = $recipients->get($run->id, collect());
                    $hasTransportEvidence = $this->hasRunTransportEvidence($run)
                        || $runRecipients->contains(function (CampaignRecipient $recipient) use ($reservations): bool {
                            $recipientReservations = $reservations->get(
                                SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT . ':' . $recipient->id,
                                collect(),
                            );

                            return filled($recipient->provider_message_id)
                                || $recipient->sent_at !== null
                                || $recipientReservations->contains(
                                    fn (SmtpSendReservation $reservation): bool => in_array($reservation->status, ['sending', 'accepted', 'uncertain', 'sent'], true)
                                        || $reservation->hasProviderTransportEvidence(),
                                );
                        });

                    $isUntouched = $runRecipients->isEmpty()
                        || $runRecipients->every(
                            fn (CampaignRecipient $recipient): bool => $recipient->status === 'queued',
                        );

                    if ($hasTransportEvidence || ! $isUntouched) {
                        throw new InvalidArgumentException('Un lot planifié contient déjà une tentative d’envoi. Vérifiez l’historique avant de reprendre la campagne.');
                    }

                    if ($run->run_at->lte($clock)) {
                        // Re-plan each stale lot after the preceding safe lot so
                        // several paused occurrences cannot collapse onto one slot.
                        $nextRunAt = $this->advanceAfter(
                            $lockedCampaign,
                            $run->run_at,
                            $pendingAt === null ? $clock : $this->later($clock, $pendingAt),
                        );
                        if ($nextRunAt === null) {
                            throw new InvalidArgumentException('Cette campagne récurrente est terminée. Renseignez le champ Premier envoi avant de l’activer.');
                        }

                        $run->update(['run_at' => $nextRunAt]);
                    }

                    // Future safe occurrences keep their date, but still form the
                    // floor for the campaign cursor and any following stale lot.
                    $pendingAt = $this->later($pendingAt, $run->run_at);
                }

                if ($lockedCampaign->next_run_at !== null) {
                    $cursorFloor = $pendingAt === null
                        ? Carbon::instance($clock)
                        : $this->later($clock, $pendingAt);
                    $cursor = $lockedCampaign->next_run_at;

                    if ($cursor->lte($cursorFloor)) {
                        $cursor = $this->advanceAfter($lockedCampaign, $cursor, $cursorFloor);
                        if ($cursor === null) {
                            throw new InvalidArgumentException('Cette campagne récurrente est terminée. Renseignez le champ Premier envoi avant de l’activer.');
                        }

                        $lockedCampaign->update(['next_run_at' => $cursor]);
                    }

                    $pendingAt = $this->later($pendingAt, $cursor);
                }
            }

            $lockedCampaign->update(['is_active' => true]);

            return $pendingAt ?? $lockedCampaign->next_run_at ?? $clock;
        }, 3);
    }

    /**
     * Finalize one untouched occurrence without changing the campaign timeline.
     *
     * Cancellation is deliberately narrower than pause: it only applies before a
     * provider-facing attempt, and it consumes the paced company ledger entries so
     * the exact occurrence cannot reappear automatically.
     */
    public function cancel(Campaign $campaign, CampaignRun $run): void
    {
        if ($campaign->schedule_type === 'sequence') {
            throw new InvalidArgumentException('Les lots d’une campagne séquence se gèrent depuis la séquence.');
        }

        DB::transaction(function () use ($campaign, $run): void {
            // Keep the same identity -> reservation -> campaign order as SMTP
            // sending. The snapshot is intentionally taken before locking the
            // identities, then re-read under those locks below.
            $identityIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join) use ($run): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
                        ->where('campaign_recipients.campaign_run_id', $run->id);
                })
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->pluck('smtp_send_reservations.sender_identity_id')
                ->push($campaign->sender_identity_id)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort();

            foreach ($identityIds as $identityId) {
                SenderIdentity::query()->lockForUpdate()->findOrFail($identityId);
            }

            $reservationIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join) use ($run): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
                        ->where('campaign_recipients.campaign_run_id', $run->id);
                })
                ->pluck('smtp_send_reservations.id');
            $reservations = SmtpSendReservation::query()
                ->whereIn('id', $reservationIds)
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->orderBy('smtp_send_reservations.id')
                ->lockForUpdate()
                ->get();

            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $lockedRun = CampaignRun::query()
                ->where('campaign_id', $lockedCampaign->id)
                ->lockForUpdate()
                ->findOrFail($run->id);
            $recipients = CampaignRecipient::query()
                ->where('campaign_run_id', $lockedRun->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $dispatches = CampaignCompanyDispatch::query()
                ->where('campaign_id', $lockedCampaign->id)
                ->where('current_run_id', $lockedRun->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (! in_array($lockedRun->status, ['prepared', 'scheduled', 'sending'], true)) {
                throw new InvalidArgumentException('Ce lot ne peut plus être annulé dans son état actuel.');
            }

            $sendingWithoutProvenSmtpReservation = $lockedRun->status === 'sending'
                && ($lockedRun->driver_ref !== 'smtp' || $reservations->isEmpty());
            $hasRunEvidence = $sendingWithoutProvenSmtpReservation
                || $this->hasRunTransportEvidence($lockedRun);
            $hasRecipientEvidence = $recipients->contains(
                fn (CampaignRecipient $recipient): bool => filled($recipient->provider_message_id) || $recipient->sent_at !== null,
            );
            $hasTransportReservation = $reservations->contains(
                fn (SmtpSendReservation $reservation): bool => ! in_array($reservation->status, ['reserved', 'released', 'failed'], true)
                    || filled($reservation->provider_message_id)
                    || $reservation->attempted_at !== null
                    || $reservation->accepted_at !== null
                    || $reservation->sent_at !== null
                    || (int) $reservation->attempt_count > 0,
            );

            if ($hasRunEvidence || $hasRecipientEvidence || $hasTransportReservation) {
                throw new InvalidArgumentException('Ce lot ne peut plus être annulé car il a déjà été transmis au fournisseur.');
            }

            $reservations->where('status', 'reserved')->each(
                fn (SmtpSendReservation $reservation) => $reservation->update(['status' => 'released', 'lease_expires_at' => null]),
            );

            CampaignRecipient::query()
                ->whereIn('id', $recipients->where('status', 'queued')->pluck('id'))
                ->update(['status' => 'skipped', 'skip_reason' => 'run_canceled']);

            foreach ($dispatches as $dispatch) {
                $dispatch->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'last_error' => 'Lot annulé par un opérateur.',
                ]);
            }

            $lockedRun->update([
                'status' => 'canceled',
                'finished_at' => now(),
                'failure_reason' => 'Annulé par un opérateur.',
            ]);
        }, 3);
    }

    /**
     * Expedite a single untouched direct-SMTP reservation without bypassing the
     * mailbox quotas, business window, or existing reservations.
     */
    public function startNow(Campaign $campaign, CampaignRun $run, ?CarbonInterface $now = null): Carbon
    {
        if ($campaign->schedule_type === 'sequence' || $campaign->delivery_channel !== 'smtp') {
            throw new InvalidArgumentException('Seuls les lots SMTP hors séquence peuvent être démarrés maintenant.');
        }

        return DB::transaction(function () use ($campaign, $run, $now): Carbon {
            // First discover all potentially involved identities, then acquire
            // locks in the same identity -> reservation -> campaign order used
            // by the SMTP worker. This prevents an expedited slot racing a send.
            $identityIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join) use ($run): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
                        ->where('campaign_recipients.campaign_run_id', $run->id);
                })
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->pluck('smtp_send_reservations.sender_identity_id')
                ->push($campaign->sender_identity_id)
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort();

            foreach ($identityIds as $identityId) {
                SenderIdentity::query()->lockForUpdate()->findOrFail($identityId);
            }

            $reservationIds = SmtpSendReservation::query()
                ->join('campaign_recipients', function ($join) use ($run): void {
                    $join->on('campaign_recipients.id', '=', 'smtp_send_reservations.source_id')
                        ->where('smtp_send_reservations.source_type', SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT)
                        ->where('campaign_recipients.campaign_run_id', $run->id);
                })
                ->pluck('smtp_send_reservations.id');
            $reservations = SmtpSendReservation::query()
                ->whereIn('id', $reservationIds)
                ->orderBy('smtp_send_reservations.sender_identity_id')
                ->orderBy('smtp_send_reservations.id')
                ->lockForUpdate()
                ->get();
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $lockedRun = CampaignRun::query()
                ->where('campaign_id', $lockedCampaign->id)
                ->lockForUpdate()
                ->findOrFail($run->id);
            $recipients = CampaignRecipient::query()
                ->where('campaign_run_id', $lockedRun->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (! $lockedCampaign->is_active) {
                throw new InvalidArgumentException('Reprenez la campagne avant de démarrer ce lot.');
            }
            if ($lockedCampaign->schedule_type === 'sequence' || $lockedCampaign->delivery_channel !== 'smtp') {
                throw new InvalidArgumentException('Seuls les lots SMTP hors séquence peuvent être démarrés maintenant.');
            }
            if (! in_array($lockedRun->status, ['scheduled', 'sending'], true)) {
                throw new InvalidArgumentException('Ce lot ne peut pas être démarré dans son état actuel.');
            }

            $hasRunEvidence = $this->hasRunTransportEvidence($lockedRun);
            $hasRecipientEvidence = $recipients->contains(
                fn (CampaignRecipient $recipient): bool => $recipient->status !== 'queued'
                    || filled($recipient->provider_message_id)
                    || $recipient->sent_at !== null,
            );
            $hasTransportEvidence = $reservations->contains(
                fn (SmtpSendReservation $reservation): bool => in_array($reservation->status, ['sending', 'accepted', 'uncertain', 'sent'], true)
                    || $reservation->hasProviderTransportEvidence(),
            );
            $reserved = $reservations->where('status', 'reserved')->values();
            $hasOnlyReusableReservations = $reservations->every(
                fn (SmtpSendReservation $reservation): bool => $reservation->isReusableBeforeTransport(),
            );
            $hasMismatchedReservation = $reservations->contains(
                fn (SmtpSendReservation $reservation): bool => (int) $reservation->campaign_id !== (int) $lockedCampaign->id
                    || (! $reservation->isReusableBeforeTransport()
                        && (int) $reservation->sender_identity_id !== (int) $lockedCampaign->sender_identity_id),
            );
            if ($hasRunEvidence || $hasRecipientEvidence || $hasTransportEvidence || $hasMismatchedReservation) {
                throw new InvalidArgumentException('Ce lot ne peut pas être démarré car il n’est plus sûr à replanifier.');
            }

            // A programmed occurrence can exist before its first reservation,
            // or retain only released/failed rows after a reversible pause.
            // Keep it scheduled and let SendCampaignJob perform the normal
            // identity-serialized reservation claim.
            if ($reserved->isEmpty() && $hasOnlyReusableReservations) {
                if ($lockedRun->status !== 'scheduled') {
                    throw new InvalidArgumentException('Ce lot ne peut pas être démarré dans son état actuel.');
                }

                $preflight = $this->campaigns->dispatchPreflight($lockedCampaign, $lockedRun);
                if (! $preflight['ok']) {
                    throw new InvalidArgumentException(implode(' ', $preflight['messages']));
                }

                $identity = SenderIdentity::query()->findOrFail($lockedCampaign->sender_identity_id);
                $scheduledFor = $this->smtpReservations->earliestSafeSlot($identity, $lockedCampaign, $now);
                $lockedRun->update(['run_at' => $scheduledFor]);

                DB::afterCommit(function () use ($lockedRun, $scheduledFor): void {
                    SendCampaignJob::dispatch($lockedRun->id)->delay($scheduledFor);
                });

                return Carbon::instance($scheduledFor->toDateTime());
            }

            if ($reserved->count() !== 1) {
                throw new InvalidArgumentException('Ce lot ne peut pas être démarré car il n’est plus sûr à replanifier.');
            }

            /** @var SmtpSendReservation $reservation */
            $reservation = $reserved->first();
            $identity = SenderIdentity::query()->findOrFail($reservation->sender_identity_id);
            $result = $this->smtpReservations->moveToEarliestSafeSlot($reservation, $identity, $lockedCampaign, $now);
            $scheduledFor = $result['send_at'];

            $lockedRun->update([
                'run_at' => $scheduledFor,
                'status' => 'sending',
                'driver_ref' => 'smtp',
                'started_at' => $lockedRun->started_at ?? now(),
                'finished_at' => null,
            ]);

            DB::afterCommit(function () use ($result): void {
                SendSmtpReservationJob::dispatch($result['reservation']->id, $result['send_at'])
                    ->delay($result['send_at']);
            });

            return Carbon::instance($scheduledFor->toDateTime());
        }, 3);
    }

    private function advanceAfter(Campaign $campaign, CarbonInterface $from, CarbonInterface $floor): ?Carbon
    {
        $candidate = Carbon::instance($from);
        $timezone = $campaign->scheduleTimezone();

        do {
            $candidate = $campaign->schedule_type === 'paced'
                ? $this->scheduler->computeNextBusinessRun($candidate, $timezone)
                : $this->scheduler->computeNextRun($campaign->recurrence ?? [], $candidate, $timezone);
        } while ($candidate !== null && $candidate->lte($floor));

        return $candidate;
    }

    private function later(?CarbonInterface $first, CarbonInterface $second): Carbon
    {
        if ($first === null || $second->gt($first)) {
            return Carbon::instance($second);
        }

        return Carbon::instance($first);
    }

    /** Provider-facing evidence which makes timeline rewrites unsafe. */
    private function hasRunTransportEvidence(CampaignRun $run): bool
    {
        return (filled($run->driver_ref) && $run->driver_ref !== 'smtp')
            || filled($run->zoho_list_key)
            || filled($run->zoho_campaign_key)
            || collect([
                $run->stats_sent,
                $run->stats_delivered,
                $run->stats_opened,
                $run->stats_clicked,
                $run->stats_bounced,
                $run->stats_unsubscribed,
                $run->stats_replied,
                $run->conversion_count,
            ])->contains(fn ($value): bool => (int) $value > 0);
    }
}
