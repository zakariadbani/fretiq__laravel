<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\EmailTrackingEvent;
use App\Models\SmtpSendReservation;
use App\Models\Suppression;
use App\Services\Campaign\CampaignService;
use App\Services\Campaign\SequenceService;
use App\Services\Campaign\SmtpCampaignsDriver;
use App\Services\Campaign\SmtpSendReservationService;
use App\Services\Mail\SenderIdentitySmtpMailer;
use App\Support\TrackingToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendSmtpReservationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 90;
    private bool $transportBoundaryCrossed = false;

    public function __construct(public readonly int $reservationId)
    {
        $this->onQueue('campaigns');
    }

    public function uniqueId(): string
    {
        return (string) $this->reservationId;
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('smtp-reservation-' . $this->reservationId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(
        SmtpSendReservationService $reservations,
        SmtpCampaignsDriver $driver,
        CampaignService $campaigns,
        SequenceService $sequences,
    ): void {
        $reservation = SmtpSendReservation::with(['campaign.senderIdentity'])->find($this->reservationId);
        if ($reservation === null || in_array($reservation->status, ['sent', 'released', 'uncertain'], true)) {
            return;
        }

        if ($reservation->status === 'accepted') {
            if ($reservation->source_type === SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT) {
                $this->sendCampaignRecipient($reservation, $driver, $reservations, $campaigns);
            } elseif ($reservation->source_type === SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND) {
                $sequences->sendReservedStep(
                    $reservation,
                    $driver,
                    $reservations,
                    function (): void { $this->transportBoundaryCrossed = true; },
                );
            }
            return;
        }

        $check = $reservations->claimWhenDue($reservation);
        if (! $check['ok']) {
            if ($check['reason'] === 'not_due' && $check['send_at'] !== null) {
                self::dispatch($reservation->id)->delay($check['send_at']);
            }
            return;
        }

        $reservation = $check['reservation'];

        try {
            if ($reservation->source_type === SmtpSendReservation::SOURCE_CAMPAIGN_RECIPIENT) {
                $this->sendCampaignRecipient($reservation, $driver, $reservations, $campaigns);
            } elseif ($reservation->source_type === SmtpSendReservation::SOURCE_SEQUENCE_STEP_SEND) {
                $sequences->sendReservedStep(
                    $reservation,
                    $driver,
                    $reservations,
                    function (): void { $this->transportBoundaryCrossed = true; },
                );
            } else {
                $reservations->release($reservation);
            }
        } catch (Throwable $exception) {
            if (! $this->transportBoundaryCrossed
                && in_array(SmtpSendReservation::find($reservation->id)?->status, ['reserved', 'sending'], true)) {
                $reservations->defer($reservation, now()->addMinutes(5));
            }
            Log::warning('[SendSmtpReservationJob] SMTP reservation failed.', [
                'reservation_id' => $reservation->id,
                'source_type' => $reservation->source_type,
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        }
    }

    private function sendCampaignRecipient(
        SmtpSendReservation $reservation,
        SmtpCampaignsDriver $driver,
        SmtpSendReservationService $reservations,
        CampaignService $campaigns,
    ): void {
        $recipient = CampaignRecipient::with(['contact.company', 'run.campaign.template.translations', 'run.campaign.senderIdentity'])
            ->find($reservation->source_id);
        if ($recipient === null) {
            $reservations->release($reservation);
            return;
        }

        $run = $recipient->run;
        $campaign = $run->campaign;
        // Campaign settings may legally change before the first accepted
        // delivery. Never let a queued old reservation choose the mailbox.
        if ($campaign->delivery_channel !== 'smtp' || (int) $campaign->sender_identity_id !== (int) $reservation->sender_identity_id) {
            $reservations->release($reservation);
            if ($campaign->delivery_channel === 'smtp' && $campaign->is_active) {
                $campaigns->continueSmtpRun($run);
            } elseif ($campaign->delivery_channel !== 'smtp') {
                // The operator switched channels before any provider accepted
                // a message. Return the existing run to the normal dispatcher
                // so it can continue through the newly selected safe driver.
                $run->update(['status' => 'scheduled', 'started_at' => null, 'finished_at' => null]);
            }
            return;
        }
        if ($reservation->status === 'accepted') {
            $this->finalizeAccepted($reservation, $recipient, $campaign, $campaigns, null, $run);
            return;
        }
        if ($recipient->provider_message_id !== null || $recipient->sent_at !== null) {
            $reservations->markSent($reservation);
            $campaigns->continueSmtpRun($run);
            return;
        }

        if (! $campaign->is_active) {
            // Pausing is reversible: keep this recipient queued and do not
            // consume a slot or accidentally mark the audience skipped.
            $reservations->release($reservation);
            return;
        }

        if (app(SenderIdentitySmtpMailer::class)->usesSenderIdentityTransport()
            && ! $campaign->senderIdentity?->hasCompleteSmtpConfiguration()) {
            // This is a definitive local preflight failure: no SMTP connection
            // was attempted, so it is safe to defer and let the operator edit
            // the sender/channel before any real delivery starts.
            $reservations->defer($reservation, now()->addMinutes(5));
            return;
        }

        $contact = $recipient->contact;
        $skipReason = null;
        if (Suppression::isSuppressed($contact->email)) {
            $skipReason = 'suppressed';
        } elseif ($contact->company?->relationship === 'prospect'
            && ! config('prospecting.cold_send_enabled', false)) {
            $skipReason = 'cold_send_disabled';
        } elseif ($contact->company?->relationship === 'prospect'
            && $contact->email_kind === 'personal') {
            $skipReason = 'personal_email';
        }

        if ($skipReason !== null) {
            $recipient->update(['status' => 'skipped', 'skip_reason' => $skipReason]);
            $reservations->release($reservation);
            $campaigns->continueSmtpRun($run);
            return;
        }

        $token = TrackingToken::generate($run->id, $contact->id);
        $tracking = EmailTrackingEvent::createForSend($recipient, $token);
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['contact' => $contact->id]);
        $this->transportBoundaryCrossed = true;
        try {
            $providerId = $driver->send($recipient, $campaign, $run, $token, $unsubscribeUrl);
        } catch (Throwable $exception) {
            // SMTP may have accepted the DATA command before a connection error.
            // Do not automatically retry a possibly delivered email.
            $reservations->markUncertainAfterTransport($reservation);
            throw $exception;
        }

        try {
            SmtpSendReservation::whereKey($reservation->id)->update([
                'status' => 'accepted', 'accepted_at' => now(), 'provider_message_id' => $providerId, 'lease_expires_at' => null,
            ]);
        } catch (Throwable $exception) {
            // Acceptance is now possible even though local persistence failed.
            $reservations->markUncertainAfterTransport($reservation);
            throw $exception;
        }
        $reservation->refresh();
        $this->finalizeAccepted($reservation, $recipient, $campaign, $campaigns, $tracking, $run);
    }

    private function finalizeAccepted(SmtpSendReservation $reservation, CampaignRecipient $recipient, $campaign, CampaignService $campaigns, $tracking = null, $run = null): void
    {
        DB::transaction(function () use ($recipient, $campaign, $reservation, $tracking): void {
            $fresh = SmtpSendReservation::lockForUpdate()->findOrFail($reservation->id);
            if ($fresh->status !== 'accepted') return;
            $providerId = $fresh->provider_message_id;
            $tracking ??= EmailTrackingEvent::where('trackable_type', CampaignRecipient::class)
                ->where('trackable_id', $recipient->id)->latest('id')->first();
            if ($tracking !== null) {
                $tracking->markSent();
            }
            $recipient->update([
                'status' => 'sent',
                'provider_message_id' => $providerId,
                'sent_at' => now(),
            ]);
            $fresh->update(['status' => 'sent', 'sent_at' => now(), 'lease_expires_at' => null]);
            $campaign->markDeliveryStarted();
        });

        $campaigns->continueSmtpRun($run);
    }
}
