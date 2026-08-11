<?php

namespace App\Console\Commands;

use App\Jobs\SendSmtpReservationJob;
use App\Models\SmtpSendReservation;
use App\Models\CampaignRun;
use App\Services\Campaign\CampaignService;
use Illuminate\Console\Command;

class SmtpDispatchReservations extends Command
{
    protected $signature = 'smtp:dispatch-reservations {--limit=100}';
    protected $description = 'Recover and dispatch due direct-SMTP reservations.';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        // Stale claims are not resent: they may have reached the receiver.
        SmtpSendReservation::query()->where('status', 'sending')->where('lease_expires_at', '<', now())
            ->update(['status' => 'uncertain', 'lease_expires_at' => null]);

        $reservations = SmtpSendReservation::query()
            ->whereIn('status', ['reserved', 'accepted'])
            ->where('reserved_for', '<=', now())
            ->orderBy('reserved_for')
            ->limit($limit)
            ->get(['id', 'reserved_for']);

        foreach ($reservations as $reservation) {
            SendSmtpReservationJob::dispatch($reservation->id, $reservation->reserved_for);
        }

        // A campaign initially paused at zero has no reservation to recover.
        CampaignRun::with('campaign')->where('status', 'sending')->whereHas('campaign', fn ($q) => $q->where('delivery_channel', 'smtp')->where('is_active', true))
            ->orderBy('id')->limit($limit)->get()->each(function (CampaignRun $run): void {
                app(CampaignService::class)->continueSmtpRun($run);
            });

        $this->info($reservations->count() . ' réservation(s) SMTP remise(s) en file.');

        return self::SUCCESS;
    }
}
