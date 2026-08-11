<?php

namespace App\Services\Campaign;

use App\Jobs\SendCampaignJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates an audited retry of a completed non-sequence campaign run.
 *
 * A resend is deliberately a new run: provider identifiers and delivery
 * evidence on the source occurrence must remain immutable for auditability.
 */
class CampaignRunResendService
{
    public function __construct(private readonly CampaignService $campaignService)
    {
    }

    /**
     * @throws \InvalidArgumentException when the source cannot safely be resent.
     */
    public function create(Campaign $campaign, CampaignRun $sourceRun, CarbonInterface $runAt): CampaignRun
    {
        /** @var CampaignRun $resendRun */
        $resendRun = DB::transaction(function () use ($campaign, $sourceRun, $runAt): CampaignRun {
            /** @var Campaign $lockedCampaign */
            $lockedCampaign = Campaign::query()->lockForUpdate()->findOrFail($campaign->id);

            /** @var CampaignRun $lockedSource */
            $lockedSource = $lockedCampaign->runs()
                ->whereKey($sourceRun->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCampaign->schedule_type === 'sequence') {
                throw new \InvalidArgumentException('Le renvoi générique n’est pas disponible pour une campagne séquence.');
            }

            if (! $lockedCampaign->is_active) {
                throw new \InvalidArgumentException('Reprenez la campagne avant de renvoyer ce lot.');
            }

            if ($lockedSource->status !== 'sent') {
                throw new \InvalidArgumentException('Seuls les lots envoyés peuvent être renvoyés.');
            }

            $sourceContactIds = CampaignRecipient::query()
                ->where('campaign_run_id', $lockedSource->id)
                ->where('status', 'sent')
                ->lockForUpdate()
                ->pluck('contact_id')
                ->unique()
                ->values();

            $preflight = $this->campaignService->dispatchPreflight($lockedCampaign);
            if (! $preflight['ok']) {
                throw new \InvalidArgumentException(implode(' ', $preflight['messages']));
            }

            $contacts = $preflight['contacts']
                ->whereIn('id', $sourceContactIds)
                ->values();

            if ($contacts->isEmpty()) {
                throw new \InvalidArgumentException('Aucun destinataire de ce lot ne reste éligible après les vérifications actuelles.');
            }

            $resendRun = CampaignRun::create([
                'campaign_id' => $lockedCampaign->id,
                'source_run_id' => $lockedSource->id,
                'occurrence_key' => $this->occurrenceKey($lockedSource),
                'run_at' => $runAt,
                'status' => 'scheduled',
            ]);

            foreach ($contacts as $contact) {
                CampaignRecipient::create([
                    'campaign_run_id' => $resendRun->id,
                    'company_dispatch_id' => null,
                    'contact_id' => $contact->id,
                    'status' => 'queued',
                ]);
            }

            return $resendRun;
        });

        // The job is queued only after the committed run and frozen audience exist.
        SendCampaignJob::dispatch($resendRun->id);

        return $resendRun;
    }

    private function occurrenceKey(CampaignRun $sourceRun): string
    {
        return Str::limit(
            'resend-' . $sourceRun->id . '-' . now()->format('YmdHisv') . '-' . Str::lower(Str::random(6)),
            64,
            '',
        );
    }
}
