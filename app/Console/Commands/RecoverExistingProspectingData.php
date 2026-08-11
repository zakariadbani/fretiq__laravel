<?php

namespace App\Console\Commands;

use App\Data\Prospecting\RecoveryPlan;
use App\Jobs\FinalizeProspectBatchJob;
use App\Services\Prospecting\ExistingProspectingDataRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecoverExistingProspectingData extends Command
{
    protected $signature = 'prospecting:recover-existing-data
                            {--dry-run : Prévisualiser sans aucune écriture}
                            {--apply : Créer ou reprendre le lot recovery et appliquer les corrections sûres}';

    protected $description = 'Récupère les données prospecting locales sans appeler de fournisseur.';

    public function handle(ExistingProspectingDataRecoveryService $recovery): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Choisissez exactement un mode : --dry-run ou --apply.');

            return self::INVALID;
        }

        try {
            $plan = $recovery->preview();
            $this->renderSummary($plan);

            if ($dryRun) {
                $this->warn('DRY RUN — aucune écriture, aucun appel fournisseur.');

                return self::SUCCESS;
            }

            $result = $recovery->apply($plan);
            FinalizeProspectBatchJob::dispatchSync($result->batch->id);

            $this->info(sprintf(
                'Lot recovery #%d %s ; corrections sûres auditées et exceptions en revue.',
                $result->batch->id,
                $result->reused ? 'réutilisé' : 'créé',
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('[RecoverExistingProspectingData] Recovery failed', [
                'exception_class' => $exception::class,
            ]);
            $this->error('La récupération a échoué. Aucune donnée sensible n’est affichée.');

            return self::FAILURE;
        }
    }

    private function renderSummary(RecoveryPlan $plan): void
    {
        $this->line('Prévisualisation de récupération (données locales uniquement)');
        $this->line('Fingerprint: '.$plan->fingerprint);
        $this->line('Statuts de vérification à corriger: '.($plan->counts['verification_statuses'] ?? 0));
        $this->line('Emails Company Enrichment à revoir: '.($plan->counts['company_enrichment_emails'] ?? 0));
        $this->line('Domaines de snapshots à revoir: '.($plan->counts['failed_snapshot_domains'] ?? 0));
        $this->line('Téléphones Hunter à compléter: '.($plan->counts['hunter_phones'] ?? 0));
        $this->line('Tailles Hunter à compléter: '.($plan->counts['hunter_sizes'] ?? 0));
        $this->line('Appels fournisseur: 0');
    }
}
