<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SectorClassifier;
use Illuminate\Console\Command;

/**
 * NormalizeCompanySectors — backfill companies.sector to the canonical
 * taxonomy (structure/specs/sector-taxonomy.md) via SectorClassifier.
 *
 * Modeled on ReclassifyContactEmailKind (same --dry-run + summary table style).
 * MUST use Company::withRejected() — the notRejected global scope would
 * silently skip archived competitors, leaving stale raw sector values behind.
 *
 * Two-pass: pass 1 (always) computes the plan read-only; pass 2 (non-dry-run
 * only) writes the reversibility JSON BEFORE applying, then applies from the
 * exact ids recorded in that plan. Writes go through Company::save() → the
 * saving() hook, the same choke point every other writer uses.
 *
 * Safe to re-run: a second pass reports 0 changes once every row is canonical.
 *
 * Signature: companies:normalize-sectors [--dry-run]
 */
class NormalizeCompanySectors extends Command
{
    protected $signature = 'companies:normalize-sectors
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Backfill companies.sector to the canonical taxonomy via App\Support\SectorClassifier.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no writes');
        }

        [$scanned, $transitions, $reversibility, $unmapped] = $this->plan();
        $changed = array_sum(array_map('array_sum', $transitions));

        $this->info("Scanned : {$scanned} company(ies)");
        $this->info("Changed : {$changed} company(ies)");

        if ($transitions === []) {
            $this->info('All companies already have a canonical sector — nothing to update.');
        } else {
            $rows = [];
            foreach ($transitions as $old => $newMap) {
                foreach ($newMap as $new => $count) {
                    $rows[] = ["{$old} → ".($new === '' ? 'null' : $new), $count];
                }
            }
            $this->table(['Transition', 'Count'], $rows);
        }

        if ($unmapped !== []) {
            $this->warn('UNMAPPED — raw values that came back unchanged and are not canonical (extend config(\'global.data.sector_map\')):');
            $this->table(['Raw sector value', 'Count'], collect($unmapped)->map(fn ($count, $value) => [$value, $count])->values()->all());
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no writes performed. Remove --dry-run to apply.');

            return Command::SUCCESS;
        }

        if ($reversibility !== []) {
            $path = storage_path('app/sector-normalization-'.now()->format('Y_m_d_His').'.json');
            file_put_contents($path, json_encode($reversibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Reversibility log written: {$path}");
        }

        $this->apply($reversibility);

        return Command::SUCCESS;
    }

    /**
     * Read-only scan: classify every row and bucket the results. No writes.
     *
     * @return array{0: int, 1: array<string, array<string, int>>, 2: array<string, array<string, array<int, int>>>, 3: array<string, int>}
     */
    private function plan(): array
    {
        $scanned = 0;
        $companySectors = config('global.data.company_sectors', []);

        /** @var array<string, array<string, int>> $transitions e.g. ['Old' => ['New' => 5]] */
        $transitions = [];

        /** @var array<string, array<string, array<int, int>>> $reversibility ['old' => ['new' => [ids]]] */
        $reversibility = [];

        /** @var array<string, int> $unmapped raw value => count, still unchanged after classification */
        $unmapped = [];

        Company::withRejected()
            ->whereNotNull('sector')
            ->chunkById(500, function ($companies) use ($companySectors, &$scanned, &$transitions, &$reversibility, &$unmapped) {
                foreach ($companies as $company) {
                    $scanned++;

                    $old = (string) $company->sector;
                    $new = (string) SectorClassifier::canonical($old);

                    if ($new === $old) {
                        if (! in_array($old, $companySectors, true)) {
                            $unmapped[$old] = ($unmapped[$old] ?? 0) + 1;
                        }

                        continue;
                    }

                    $transitions[$old][$new] = ($transitions[$old][$new] ?? 0) + 1;
                    $reversibility[$old][$new][] = $company->id;
                }
            });

        return [$scanned, $transitions, $reversibility, $unmapped];
    }

    /**
     * Apply a previously-planned+persisted transition set, by id, through
     * Company::save() (the sector-normalization choke point).
     *
     * @param  array<string, array<string, array<int, int>>>  $reversibility
     */
    private function apply(array $reversibility): void
    {
        foreach ($reversibility as $newByOld) {
            foreach ($newByOld as $new => $ids) {
                Company::withRejected()->whereIn('id', $ids)->chunkById(500, function ($companies) use ($new) {
                    foreach ($companies as $company) {
                        $company->sector = $new;
                        $company->save();
                    }
                });
            }
        }
    }
}
