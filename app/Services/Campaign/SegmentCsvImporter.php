<?php

namespace App\Services\Campaign;

use App\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CSV columns: name;scope;filter_json;notes
 *
 * filter_json is validated against the same filter.* rules Segment::rules()
 * applies to the live create/edit form. Unknown top-level keys are stripped
 * with a preview warning rather than rejected outright — SegmentService::
 * applyJsonFilter() already silently ignores unknown keys at resolve time, so
 * persisting them would just be inert noise; stripping at import time keeps
 * the stored filter honest about what will actually be applied.
 *
 * notes is display-only in the preview — Segment has no notes column.
 */
class SegmentCsvImporter extends AbstractCsvImporter
{
    public const HEADERS = ['name', 'scope', 'filter_json', 'notes'];

    public function headers(): array
    {
        return self::HEADERS;
    }

    protected function normalizeRow(array $raw, int $rowNumber, array &$errors): ?array
    {
        $name = trim((string) ($raw['name'] ?? ''));
        if ($name === '') {
            $errors[] = "Ligne {$rowNumber} : le nom est requis.";

            return null;
        }

        $scopes = array_keys(config('global.data.segment_scopes', []));
        $scope = trim((string) ($raw['scope'] ?? ''));
        if (! in_array($scope, $scopes, true)) {
            $errors[] = "Ligne {$rowNumber} : scope « {$scope} » invalide (attendu : ".implode(', ', $scopes).').';

            return null;
        }

        $filterJson = trim((string) ($raw['filter_json'] ?? ''));
        $filter = [];
        $warnings = [];

        if ($filterJson !== '') {
            try {
                $decoded = json_decode($filterJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $errors[] = "Ligne {$rowNumber} : filter_json n'est pas un JSON valide.";

                return null;
            }

            if (! is_array($decoded)) {
                $errors[] = "Ligne {$rowNumber} : filter_json doit être un objet JSON.";

                return null;
            }

            $knownKeys = $this->knownFilterKeys();
            $unknown = array_diff(array_keys($decoded), $knownKeys);
            if ($unknown !== []) {
                $warnings[] = 'Clés de filtre ignorées (non reconnues) : '.implode(', ', $unknown).'.';
                $decoded = array_intersect_key($decoded, array_flip($knownKeys));
            }

            $validator = Validator::make(['filter' => $decoded], $this->filterRules());
            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $errors[] = "Ligne {$rowNumber} : {$message}";
                }

                return null;
            }

            $filter = $decoded;
        }

        return [
            'name' => $name,
            'scope' => $scope,
            'filter' => $filter === [] ? null : $filter,
            'notes' => $this->nullableText($raw['notes'] ?? null),
            'warnings' => $warnings,
            'will_update' => Segment::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists(),
        ];
    }

    /** @return list<string> */
    private function knownFilterKeys(): array
    {
        return collect((new Segment)->rules())
            ->keys()
            ->filter(fn ($key) => str_starts_with($key, 'filter.'))
            ->map(fn ($key) => explode('.', $key)[1])
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string|array<string>> */
    private function filterRules(): array
    {
        return collect((new Segment)->rules())
            ->filter(fn ($rule, $key) => $key === 'filter' || str_starts_with($key, 'filter.'))
            ->all();
    }

    /**
     * Upsert by case-insensitive name. New segments are always is_manual=0
     * (dynamic/filter-driven).
     *
     * Existing segments are never silently widened or de-manualized: a
     * segment is a live campaign's audience, so an empty filter_json cell
     * must not null out a real existing filter (SegmentService resolves a
     * null filter to the entire scope audience), and an existing manual
     * (pinned-contact) segment must not be flipped to dynamic — there is no
     * explicit opt-in for either, so both are always refused and reported
     * as a warning rather than applied.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{created: int, updated: int, warnings: list<string>}
     */
    public function store(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $created = 0;
            $updated = 0;
            $warnings = [];

            foreach ($rows as $row) {
                $existing = Segment::whereRaw('LOWER(name) = ?', [mb_strtolower($row['name'])])->first();

                if ($existing === null) {
                    Segment::create([
                        'name' => $row['name'],
                        'scope' => $row['scope'],
                        'is_manual' => false,
                        'filter' => $row['filter'],
                    ]);
                    $created++;
                    continue;
                }

                $attrs = ['scope' => $row['scope']];

                if ($row['filter'] === null && $existing->filter !== null) {
                    $warnings[] = "« {$existing->name} » : filtre existant conservé (filter_json vide dans le fichier).";
                } else {
                    $attrs['filter'] = $row['filter'];
                }

                if ($existing->is_manual) {
                    $warnings[] = "« {$existing->name} » : segment manuel conservé (is_manual inchangé).";
                } else {
                    $attrs['is_manual'] = false;
                }

                $existing->fill($attrs)->save();
                $updated++;
            }

            return ['created' => $created, 'updated' => $updated, 'warnings' => $warnings];
        });
    }
}
