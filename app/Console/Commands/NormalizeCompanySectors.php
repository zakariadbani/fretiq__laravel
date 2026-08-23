<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ProspectCriteria;
use App\Models\Segment;
use App\Support\SectorClassifier;
use Illuminate\Console\Command;

/**
 * NormalizeCompanySectors — backfill raw sector labels to the canonical
 * taxonomy (structure/specs/sector-taxonomy.md) via SectorClassifier.
 *
 * Three backfill targets, each with its own read-only plan pass + apply pass:
 *   - companies.sector            scalar column; Company::saving() already
 *                                  canonicalizes every NEW dirty write — this
 *                                  backfills rows that predate that hook / the
 *                                  taxonomy.
 *   - segments.filter->sector      flat JSON filter array (['sector' => [...], ...]);
 *                                  Segment has NO model events, so a raw label
 *                                  saved here never gets a chance to normalize
 *                                  itself and needs this backfill to ever change.
 *   - prospect_criteria.sectors    bare JSON list (["Label", ...]); see the
 *                                  IMPORTANT side-effect note on
 *                                  applyProspectCriteria() below.
 *
 * Deliberately OUT OF SCOPE:
 *   - prospect_criteria.ai_queries — sector words only ever appear inside
 *     free-text query strings there, never as a structured label.
 *   - The Zoho mirror industry columns — raw external vocabulary owned by the
 *     mirror, not this app's taxonomy.
 *
 * Modeled on ReclassifyContactEmailKind (same --dry-run + summary table style).
 * MUST use Company::withRejected() — the notRejected global scope would
 * silently skip archived competitors, leaving stale raw sector values behind.
 * Segment and ProspectCriteria carry no global scopes, so those two passes
 * query the model directly.
 *
 * Two-pass per target: pass 1 (always) computes the plan read-only; pass 2
 * (non-dry-run only) writes ONE reversibility JSON —
 * {"companies": ..., "segments": ..., "prospect_criteria": ...} — BEFORE
 * applying, then applies from the exact ids recorded in that plan.
 *
 * IMPORTANT side effect: applyProspectCriteria() uses a plain ->save(), never
 * saveQuietly() — a sectors change legitimately trips ProspectCriteria's own
 * discoverTargetingChanged() invariant, nulling hunter_discover_filters /
 * hunter_discover_prompt_hash and resetting the Hunter discovery cursor,
 * because canonicalizing a label IS a targeting-text change from that
 * model's point of view.
 *
 * Safe to re-run: a second pass reports 0 changes once every row is canonical.
 *
 * Signature: companies:normalize-sectors [--dry-run]
 */
class NormalizeCompanySectors extends Command
{
    protected $signature = 'companies:normalize-sectors
                            {--dry-run : Preview changes without writing to the database}';

    protected $description = 'Backfill companies.sector, segments.filter->sector and prospect_criteria.sectors to the canonical taxonomy via App\Support\SectorClassifier.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no writes');
        }

        $companySectors = (array) config('global.data.company_sectors', []);

        /** @var array<string, int> $unmapped shared across all three passes */
        $unmapped = [];

        [$companyScanned, $companyTransitions, $companyReversibility] = $this->planCompanies($companySectors, $unmapped);
        [$segmentScanned, $segmentTransitions, $segmentReversibility] = $this->planSegments($companySectors, $unmapped);
        [$criteriaScanned, $criteriaTransitions, $criteriaReversibility] = $this->planProspectCriteria($companySectors, $unmapped);

        $this->reportEntity('company(ies)', $companyScanned, $companyTransitions);
        $this->reportEntity('segment(s)', $segmentScanned, $segmentTransitions);
        $this->reportEntity('prospect criteria row(s)', $criteriaScanned, $criteriaTransitions);

        if ($unmapped !== []) {
            $this->warn('UNMAPPED — raw values that came back unchanged and are not canonical (extend config(\'global.data.sector_map\')):');
            $this->table(['Raw sector value', 'Count'], collect($unmapped)->map(fn ($count, $value) => [$value, $count])->values()->all());
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no writes performed. Remove --dry-run to apply.');

            return Command::SUCCESS;
        }

        $reversibility = [
            'companies'         => $companyReversibility,
            'segments'          => $segmentReversibility,
            'prospect_criteria' => $criteriaReversibility,
        ];

        if ($companyReversibility !== [] || $segmentReversibility !== [] || $criteriaReversibility !== []) {
            $path = storage_path('app/sector-normalization-'.now()->format('Y_m_d_His').'.json');
            file_put_contents($path, json_encode($reversibility, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Reversibility log written: {$path}");
        }

        $this->applyCompanies($companyReversibility);
        $this->applySegments($segmentReversibility);
        $this->applyProspectCriteria($criteriaReversibility);

        return Command::SUCCESS;
    }

    /**
     * Print the "Scanned : N / Changed : N" lines + transition table for one entity.
     *
     * @param  array<string, array<string, int>>  $transitions
     */
    private function reportEntity(string $label, int $scanned, array $transitions): void
    {
        $changed = array_sum(array_map('array_sum', $transitions));

        $this->info("Scanned : {$scanned} {$label}");
        $this->info("Changed : {$changed} {$label}");

        if ($transitions === []) {
            $this->info("Nothing to update — all {$label} already canonical.");

            return;
        }

        $rows = [];
        foreach ($transitions as $old => $newMap) {
            foreach ($newMap as $new => $count) {
                $rows[] = ["{$old} → ".($new === '' ? 'null' : $new), $count];
            }
        }
        $this->table(['Transition', 'Count'], $rows);
    }

    /**
     * Map a list of raw sector labels to canonical form: drop junk (classifier
     * returns null), dedup preserving first-occurrence order, and record
     * label-level transitions + unmapped (unknown, non-canonical, unchanged)
     * entries into the shared trackers. Shared by planSegments() and
     * planProspectCriteria() — both entities store a bare JSON list of labels.
     *
     * @param  array<int, string>                 $raw
     * @param  array<int, string>                 $companySectors
     * @param  array<string, int>                 $unmapped      Shared bucket, appended in place.
     * @param  array<string, array<string, int>>  $transitions   Shared bucket, appended in place.
     * @return array<int, string>
     */
    private function mapSectorList(array $raw, array $companySectors, array &$unmapped, array &$transitions): array
    {
        $mapped = [];

        foreach ($raw as $value) {
            $new = SectorClassifier::canonical($value);

            if ($new === null) {
                // Junk → dropped entirely, never added to $mapped.
                $transitions[$value][''] = ($transitions[$value][''] ?? 0) + 1;

                continue;
            }

            if ($new === $value) {
                if (! in_array($value, $companySectors, true)) {
                    $unmapped[$value] = ($unmapped[$value] ?? 0) + 1;
                }
            } else {
                $transitions[$value][$new] = ($transitions[$value][$new] ?? 0) + 1;
            }

            if (! in_array($new, $mapped, true)) {
                $mapped[] = $new;
            }
        }

        return $mapped;
    }

    /**
     * Read-only scan: classify every companies.sector row and bucket the
     * results. No writes.
     *
     * @param  array<int, string>  $companySectors
     * @param  array<string, int>  $unmapped  Shared bucket, appended in place.
     * @return array{0: int, 1: array<string, array<string, int>>, 2: array<string, array<string, array<int, int>>>}
     */
    private function planCompanies(array $companySectors, array &$unmapped): array
    {
        $scanned = 0;

        /** @var array<string, array<string, int>> $transitions e.g. ['Old' => ['New' => 5]] */
        $transitions = [];

        /** @var array<string, array<string, array<int, int>>> $reversibility ['old' => ['new' => [ids]]] */
        $reversibility = [];

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

        return [$scanned, $transitions, $reversibility];
    }

    /**
     * Apply a previously-planned+persisted transition set, by id, through
     * Company::save() (the sector-normalization choke point).
     *
     * @param  array<string, array<string, array<int, int>>>  $reversibility
     */
    private function applyCompanies(array $reversibility): void
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

    /**
     * Read-only scan of segments.filter->sector. No writes.
     *
     * @param  array<int, string>  $companySectors
     * @param  array<string, int>  $unmapped  Shared bucket, appended in place.
     * @return array{0: int, 1: array<string, array<string, int>>, 2: array<int, array{id: int, old: array<int, string>, new: array<int, string>}>}
     */
    private function planSegments(array $companySectors, array &$unmapped): array
    {
        $scanned = 0;
        $transitions = [];
        $reversibility = [];

        Segment::query()
            ->whereNotNull('filter')
            ->chunkById(500, function ($segments) use ($companySectors, &$scanned, &$transitions, &$reversibility, &$unmapped) {
                foreach ($segments as $segment) {
                    $scanned++;

                    $filter = $segment->filter ?? [];
                    $raw = $filter['sector'] ?? null;

                    if (is_string($raw) && $raw !== '') {
                        $raw = [$raw];
                    }

                    if (! is_array($raw) || $raw === []) {
                        continue;
                    }

                    $old = array_values(array_map(static fn ($v) => (string) $v, $raw));
                    $new = $this->mapSectorList($old, $companySectors, $unmapped, $transitions);

                    if ($new === $old) {
                        continue;
                    }

                    $reversibility[] = ['id' => $segment->id, 'old' => $old, 'new' => $new];
                }
            });

        return [$scanned, $transitions, $reversibility];
    }

    /**
     * Apply the segments plan: rewrite filter->sector (or drop the key / null
     * the whole filter once it empties out), preserving every other filter key.
     *
     * @param  array<int, array{id: int, old: array<int, string>, new: array<int, string>}>  $reversibility
     */
    private function applySegments(array $reversibility): void
    {
        if ($reversibility === []) {
            return;
        }

        $segments = Segment::whereIn('id', array_column($reversibility, 'id'))->get()->keyBy('id');

        foreach ($reversibility as $entry) {
            $segment = $segments->get($entry['id']);

            if (! $segment) {
                continue;
            }

            $filter = $segment->filter ?? [];

            if ($entry['new'] === []) {
                unset($filter['sector']);
            } else {
                $filter['sector'] = $entry['new'];
            }

            // Mirrors SegmentController::beforeSave's empty($filter) ? null : $filter.
            $segment->filter = $filter === [] ? null : $filter;
            $segment->save();
        }
    }

    /**
     * Read-only scan of prospect_criteria.sectors. No writes.
     *
     * @param  array<int, string>  $companySectors
     * @param  array<string, int>  $unmapped  Shared bucket, appended in place.
     * @return array{0: int, 1: array<string, array<string, int>>, 2: array<int, array{id: int, old: array<int, string>, new: array<int, string>}>}
     */
    private function planProspectCriteria(array $companySectors, array &$unmapped): array
    {
        $scanned = 0;
        $transitions = [];
        $reversibility = [];

        ProspectCriteria::query()
            ->whereNotNull('sectors')
            ->chunkById(500, function ($rows) use ($companySectors, &$scanned, &$transitions, &$reversibility, &$unmapped) {
                foreach ($rows as $criteria) {
                    $scanned++;

                    $raw = $criteria->sectors ?? [];

                    if (! is_array($raw) || $raw === []) {
                        continue;
                    }

                    $old = array_values(array_map(static fn ($v) => (string) $v, $raw));
                    $new = $this->mapSectorList($old, $companySectors, $unmapped, $transitions);

                    if ($new === $old) {
                        continue;
                    }

                    $reversibility[] = ['id' => $criteria->id, 'old' => $old, 'new' => $new];
                }
            });

        return [$scanned, $transitions, $reversibility];
    }

    /**
     * Apply the prospect_criteria plan via plain ->save() — NOT saveQuietly().
     * A sectors change is a real targeting-text change from
     * ProspectCriteria::discoverTargetingChanged()'s point of view, so this
     * intentionally lets its updating() hook null hunter_discover_filters /
     * hunter_discover_prompt_hash and reset the Hunter discovery cursor
     * (hunter_discover_offset / hunter_discover_exhausted).
     *
     * @param  array<int, array{id: int, old: array<int, string>, new: array<int, string>}>  $reversibility
     */
    private function applyProspectCriteria(array $reversibility): void
    {
        if ($reversibility === []) {
            return;
        }

        $rows = ProspectCriteria::whereIn('id', array_column($reversibility, 'id'))->get()->keyBy('id');

        foreach ($reversibility as $entry) {
            $criteria = $rows->get($entry['id']);

            if (! $criteria) {
                continue;
            }

            $criteria->sectors = $entry['new'] === [] ? null : $entry['new'];
            $criteria->save();
        }
    }
}
