<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Discovery\EnrichmentDrainService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * ProspectDrainEnrichment — burn leftover Hunter quota against the enrichment
 * backlog before a monthly plan reset wipes it.
 *
 * MONEY-SPENDING: a real run consumes Hunter units (enrich ≈ 1.2/company,
 * verify 0.5/email). --max is the binding cap on companies enriched and is
 * REQUIRED for any real company enrichment. --dry-run only prints the estimate
 * and spends nothing (safe on live).
 *
 *   php artisan prospect:drain-enrichment --dry-run
 *   php artisan prospect:drain-enrichment --mode=companies --max=50
 *   php artisan prospect:drain-enrichment --mode=full --max=50 --include-empty
 *   php artisan prospect:drain-enrichment --mode=verify
 */
class ProspectDrainEnrichment extends Command
{
    protected $signature = 'prospect:drain-enrichment
                            {--mode=full : full|companies|verify}
                            {--max= : Nombre max d\'entreprises à enrichir (requis sauf --dry-run)}
                            {--include-empty : Inclure aussi les entreprises hunter_empty}
                            {--dry-run : Afficher l\'estimation sans rien dépenser}';

    protected $description = 'Draine le backlog d\'enrichissement Hunter (entreprises + vérification emails). CLI surveillée — --max plafonne la dépense.';

    public function handle(EnrichmentDrainService $drain): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, ['full', 'companies', 'verify'], true)) {
            $this->error("--mode invalide : « {$mode} ». Valeurs acceptées : full, companies, verify.");

            return self::FAILURE;
        }

        $includeEmpty = (bool) $this->option('include-empty');

        // ── Dry-run: estimate only, spends nothing (safe on a live balance) ────
        if ($this->option('dry-run')) {
            $this->renderEstimate($drain, $includeEmpty);

            return self::SUCCESS;
        }

        // ── Real run: --max is the binding money cap for company enrichment ────
        $max = null;
        if (in_array($mode, ['companies', 'full'], true)) {
            $max = filter_var($this->option('max'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($max === false) {
                $this->error('--max est requis pour une exécution réelle et doit être un entier ≥ 1.');

                return self::FAILURE;
            }
        }

        // Single-flight: one authoritative marker (`drain:active`) shared by the
        // CLI, the web button and the queued job — a drain on any channel blocks
        // the others. Refreshed per company in runCompanies() so a long --max run
        // never lets the 1200s TTL lapse mid-drain.
        if (! Cache::add('drain:active', 'cli', 1200)) {
            $this->error('Un drain est déjà en cours.');

            return self::FAILURE;
        }

        try {
            if (in_array($mode, ['companies', 'full'], true)) {
                $this->runCompanies($drain, (int) $max, $includeEmpty);
            }

            if (in_array($mode, ['verify', 'full'], true)) {
                $this->runVerify($drain);
            }
        } finally {
            Cache::forget('drain:active');
        }

        return self::SUCCESS;
    }

    private function renderEstimate(EnrichmentDrainService $drain, bool $includeEmpty): void
    {
        $e = $drain->estimate($includeEmpty);

        $this->info('Estimation du drain — aucune dépense (include-empty : '.($includeEmpty ? 'oui' : 'non').').');
        $this->table(
            ['Poste', 'Valeur'],
            [
                ['Entreprises éligibles', $e['companies_eligible']],
                ['~ Crédits entreprises (×1.2)', $e['company_credits']],
                ['Contacts non vérifiés', $e['contacts_unverified']],
                ['~ Crédits vérification (×0.5)', $e['contact_credits']],
                ['~ Crédits totaux', $e['total_credits']],
            ],
        );
    }

    private function runCompanies(EnrichmentDrainService $drain, int $max, bool $includeEmpty): void
    {
        $eligible = $drain->eligibleCompaniesQuery($includeEmpty)->count();
        $target = min($max, $eligible);

        $this->info("Enrichissement des entreprises — plafond --max={$max}, {$eligible} éligible(s), cible {$target}.");

        if ($target < 1) {
            $this->line('Aucune entreprise éligible. Rien à enrichir.');

            return;
        }

        $bar = $this->output->createProgressBar($target);
        $bar->start();

        $summary = $drain->drainCompanies(
            $max,
            null,
            null,
            static function (Company $company, string $outcome) use ($bar): void {
                $bar->advance();
                // Keep the shared single-flight marker alive: a long run that
                // outlasts the 1200s TTL must never let a second drain slip in.
                Cache::put('drain:active', 'cli', 1200);
            },
            $includeEmpty,
        );

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Résultat', 'Nombre'],
            [
                ['Entreprises traitées', $summary['processed']],
                ['Entreprises enrichies', $summary['enriched']],
                ['Vides (hunter_empty)', $summary['empty']],
                ['Échecs (provider_failed)', $summary['failed']],
                ['Motif d\'arrêt', $summary['stopped_reason'] ?? '—'],
                ['Backlog épuisé', $summary['exhausted'] ? 'oui' : 'non'],
            ],
        );

        if ($summary['stopped_reason'] !== null) {
            $this->warn("Drain interrompu : {$summary['stopped_reason']}.");
        }
    }

    private function runVerify(EnrichmentDrainService $drain): void
    {
        if (config('queue.default') !== 'sync') {
            $this->warn(
                'File d\'attente non synchrone : la vérification sera seulement mise en file sur la queue « prospecting ». '.
                'Lancez un worker pour qu\'elle s\'exécute : php artisan queue:work --queue=prospecting'
            );
        }

        $enqueued = $drain->drainContacts();
        $this->info("Vérification des emails : {$enqueued} contact(s) mis en file.");
    }
}
