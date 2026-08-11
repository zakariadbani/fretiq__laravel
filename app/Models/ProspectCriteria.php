<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProspectCriteria extends Model
{
    use Validator;

    /**
     * Durable metadata stored alongside provider cursors. The run id prevents a
     * completed collection from a previous discovery from finalising a newer run.
     */
    public const DISCOVERY_COLLECTION_COMPLETE_RUN_KEY = '_collection_complete_run_id';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'prospect_criteria';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'ai_target',
        'ai_exclude',
        'ai_queries',
        'discovery_cursors',
        'hunter_discover_filters',
        'hunter_discover_prompt_hash',
        'hunter_discover_offset',
        'hunter_discover_exhausted',
        'sectors',
        'countries',
        'company_sizes',
        'target_positions',
        'daily_limit',
        'auto_run',
        'run_at_hour',
        'contact_limit',
        'min_score_enrich',
        'auto_enrich',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'ai_queries' => 'array',
        'discovery_cursors' => 'array',
        'hunter_discover_filters' => 'array',
        'hunter_discover_offset' => 'integer',
        'hunter_discover_exhausted' => 'boolean',
        'sectors' => 'array',
        'countries' => 'array',
        'company_sizes' => 'array',
        'target_positions' => 'array',
        'daily_limit' => 'integer',
        'auto_run' => 'boolean',
        'run_at_hour' => 'integer',
        'contact_limit' => 'integer',
        'min_score_enrich' => 'integer',
        'auto_enrich' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Keep all writes engine-neutral, including imports and direct model saves.
     * Legacy duplicates collapse by trimmed query and remain enabled when any
     * duplicate was enabled.
     */
    public function setAiQueriesAttribute(mixed $value): void
    {
        if ($value === null) {
            $this->attributes['ai_queries'] = null;

            return;
        }

        $rows = is_array($value) ? $value : [];
        $normalized = [];
        foreach ($rows as $row) {
            $q = is_array($row) ? trim((string) ($row['q'] ?? '')) : '';
            if ($q === '') {
                continue;
            }
            if (! isset($normalized[$q])) {
                $normalized[$q] = ['q' => $q, 'enabled' => false];
            }
            $normalized[$q]['enabled'] = $normalized[$q]['enabled'] || (bool) ($row['enabled'] ?? true);
        }

        $this->attributes['ai_queries'] = json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Invalidate the cached AI queries and un-hide previously rejected companies
     * whenever the targeting description changes — a stale ai_queries cache or a
     * stale reject would otherwise silently survive an edited intent.
     *
     * Skipped when the same save already supplies fresh ai_queries (form submits
     * ai_target/ai_exclude + ai_queries together) — otherwise this would wipe out
     * the queries the user just reviewed and saved in the same request.
     */
    protected static function booted(): void
    {
        static::updating(function (self $m): void {
            if (($m->isDirty('ai_target') || $m->isDirty('ai_exclude')) && ! $m->isDirty('ai_queries')) {
                $m->ai_queries = null;
            }

            if ($m->discoverTargetingChanged()) {
                $m->hunter_discover_filters = null;
                $m->hunter_discover_prompt_hash = null;
                $m->hunter_discover_offset = 0;
                $m->hunter_discover_exhausted = false;
            }
        });

        static::updated(function (self $m): void {
            if ($m->wasChanged('ai_target') || $m->wasChanged('ai_exclude')) {
                Company::withRejected()
                    ->where('criteria_id', $m->id)
                    ->where('qualification_status', 'rejected')
                    ->update(['qualification_status' => 'pending']);
            }
        });
    }

    /**
     * Canonical input used by Hunter Discover prompts and invalidation. Ordering
     * and insignificant whitespace do not alter the resulting JSON/hash.
     *
     * @return array{target:string,exclude:?string,sectors:list<string>,countries:list<string>,sizes:list<string>}
     */
    public static function canonicalDiscoverTargeting(
        mixed $target,
        mixed $exclude,
        mixed $sectors,
        mixed $countries,
        mixed $sizes,
    ): array {
        $target = self::normalizeDiscoverText($target);
        $exclude = self::normalizeDiscoverText($exclude);

        return [
            'target' => $target,
            'exclude' => $exclude === '' ? null : $exclude,
            'sectors' => self::normalizeDiscoverSet($sectors),
            'countries' => self::normalizeDiscoverSet($countries),
            'sizes' => self::normalizeDiscoverSet($sizes),
        ];
    }

    private function discoverTargetingChanged(): bool
    {
        if (! $this->isDirty(['ai_target', 'ai_exclude', 'sectors', 'countries', 'company_sizes'])) {
            return false;
        }

        $before = self::canonicalDiscoverTargeting(
            $this->getRawOriginal('ai_target'),
            $this->getRawOriginal('ai_exclude'),
            self::decodeDiscoverArray($this->getRawOriginal('sectors')),
            self::decodeDiscoverArray($this->getRawOriginal('countries')),
            self::decodeDiscoverArray($this->getRawOriginal('company_sizes')),
        );
        $after = self::canonicalDiscoverTargeting(
            $this->ai_target,
            $this->ai_exclude,
            $this->sectors,
            $this->countries,
            $this->company_sizes,
        );

        return self::canonicalDiscoverJson($before) !== self::canonicalDiscoverJson($after);
    }

    private static function normalizeDiscoverText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    /** @return list<string> */
    private static function normalizeDiscoverSet(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = self::normalizeDiscoverText($value);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }
        $normalized = array_values($normalized);
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    /** @return list<mixed> */
    private static function decodeDiscoverArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param array<string, mixed> $value */
    private static function canonicalDiscoverJson(array $value): string
    {
        ksort($value, SORT_STRING);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Companies targeted by this criteria set.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'criteria_id');
    }

    /**
     * Contacts reached through this criteria's companies.
     * Contact -> Company -> ProspectCriteria (companies.criteria_id, contacts.company_id).
     */
    public function contacts(): HasManyThrough
    {
        return $this->hasManyThrough(Contact::class, Company::class, 'criteria_id', 'company_id', 'id', 'id');
    }

    /**
     * All discovery runs for this criteria set.
     */
    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class, 'prospect_criteria_id');
    }

    /**
     * Prospecting-center batches created from this criteria set.
     */
    public function prospectBatches(): HasMany
    {
        return $this->hasMany(ProspectBatch::class, 'prospect_criteria_id');
    }

    /**
     * The most recent discovery-type run (uses ofMany for a single-row eager-load).
     * Only considers rows with type='discovery' so that manual enrichment runs
     * (type='manual') do not surface as the "latest" run on the criteria panel.
     */
    public function latestDiscoveryRun(): HasOne
    {
        return $this->hasOne(DiscoveryRun::class, 'prospect_criteria_id')
            ->ofMany(['id' => 'max'], fn ($q) => $q->where('type', 'discovery'));
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     * The Validator trait calls this via validator() / validate().
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'ai_target' => 'nullable|string|max:2000',
            'ai_exclude' => 'nullable|string|max:2000',
            // Discovery engines are selected globally in Settings. Criteria rows
            // intentionally contain only the query text and its enabled flag.
            'ai_queries' => 'nullable|array',
            'ai_queries.*.q' => 'required|string|max:500',
            'ai_queries.*.enabled' => 'boolean',
            'hunter_discover_filters' => 'nullable|array',
            'hunter_discover_prompt_hash' => 'nullable|string|size:64',
            'hunter_discover_offset' => 'nullable|integer|min:0',
            'hunter_discover_exhausted' => 'nullable|boolean',
            'sectors' => 'nullable|array|max:50',
            'sectors.*' => 'string|max:100',
            'countries' => 'nullable|array|max:50',
            'countries.*' => 'string|max:10',
            'company_sizes' => 'nullable|array|max:20',
            'company_sizes.*' => 'string|max:20',
            'target_positions' => 'nullable|array|max:50',
            'target_positions.*' => 'string|max:100',
            'daily_limit' => 'nullable|integer|min:1|max:500',
            'auto_run' => 'boolean',
            'run_at_hour' => 'nullable|integer|between:0,23|required_if:auto_run,1',
            'contact_limit' => 'nullable|integer|min:1|max:20',
            'min_score_enrich' => 'nullable|integer|between:0,100',
            'auto_enrich' => 'nullable|boolean',
            'is_active' => 'boolean',
        ];
    }
}
