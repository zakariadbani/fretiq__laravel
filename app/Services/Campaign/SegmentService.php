<?php

namespace App\Services\Campaign;

use App\Models\Contact;
use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Suppression;
use App\Services\Prospecting\ContactLifecycleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SegmentService — THE single compliance point for resolving a segment's audience.
 *
 * All compliance rules are enforced here (and re-checked at send time in
 * CampaignService). No other service bypasses these rules.
 *
 * Rules applied (in order):
 *   1. Scope filter: client | prospect | mixed (company.relationship).
 *   2. JSON filter: best-effort on a small set of known keys (scalar or array values).
 *   3. Suppression exclusion: LOWER(TRIM(email)) normalized subquery (B3).
 *   4. Deduplicate by email (application-level, case-insensitive on trimmed email).
 *   5. Subtract manual excludes (after dedup — exclude always wins).
 *
 * Merge semantics (hybrid smart-list):
 *   Final audience = pipeline( (filter_matches ∪ manual_includes) − manual_excludes )
 *
 *   Includes: UNIONed at scope+filter boundary (stage 1+2), BEFORE compliance.
 *     A pinned-in contact bypasses scope+filter only — faces stages 3–5 unchanged.
 *   Excludes: subtracted AFTER dedup (stage 5), unconditionally.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BEHAVIOR CHANGES (2026-06-10, D11):
 *   (a) Empty / whitespace-only emails are now excluded from all paths.
 *   (b) Deduplication is now case-insensitive on the trimmed email.
 * BEHAVIOR CHANGES (2026-06-23, hybrid smart-list):
 *   (c) buildBaseQuery accepts $includeIds — ORed with scope+filter in a nested where().
 *   (d) applySuppressionStage normalized to LOWER(TRIM()) (B3).
 *   (e) resolveCollection() is the single hydrated source of truth (N2).
 *   (f) resolveWithStats() derives final/manually_excluded from resolveCollection (N2).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @see compliance-deliverability.md §1, §2
 * @see campaign-automation.md §5
 */
class SegmentService
{
    public function __construct(
        private readonly ContactEligibilityService $contactEligibility,
        private readonly ContactLifecycleService $lifecycle,
    ) {
    }

    /**
     * Resolve the audience for a segment, with all compliance filters applied.
     * This is the single entry point for the send-path and the contacts table.
     *
     * @return Collection<int, Contact>  Eligible Contact models (with 'company' relation loaded).
     */
    public function resolve(Segment $segment, string $policy = Campaign::VERIFICATION_VERIFIED_ONLY): Collection
    {
        return $this->resolveCollection($segment, $policy);
    }

    /**
     * Resolve the audience for a given scope + filter + pin sets, without a saved Segment.
     * Used by the live-preview contacts path (contacts() with a non-empty request scope).
     * Applies the full compliance pipeline (identical to resolveCollection).
     *
     * @param  string    $scope       One of: 'client', 'prospect', 'mixed'
     * @param  array     $filter      Normalized filter array (keys: sector, country, status)
     * @param  int[]     $includeIds  Contact IDs to force-include (pinned-in from saved segment)
     * @param  int[]     $excludeIds  Contact IDs to force-exclude (pinned-out from saved segment)
     * @param  bool      $manualOnly  Resolve the include IDs as an explicit allow-list
     * @return Collection<int, Contact>
     */
    public function resolveAudience(string $scope, array $filter, array $includeIds = [], array $excludeIds = [], bool $manualOnly = false, string $policy = Campaign::VERIFICATION_VERIFIED_ONLY): Collection
    {
        $qualityEligible = $this->filterByQuality(
            $this->buildPostDedupCollection($scope, $filter, $includeIds, $manualOnly),
            $policy,
        );

        return $qualityEligible
            ->reject(fn (Contact $c) => in_array($c->id, $excludeIds, true))
            ->values();
    }

    /**
     * Return the count of eligible contacts for a segment.
     * Delegates to resolveWithStats for a truthful, pipeline-consistent count.
     */
    public function previewCount(Segment $segment, string $policy = Campaign::VERIFICATION_VERIFIED_ONLY): int
    {
        return $this->resolveWithStats(
            $segment->scope,
            $segment->filter ?? [],
            false,
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
            $segment->is_manual,
            $policy,
        )['final'];
    }

    /**
     * Run the full compliance pipeline and return per-stage funnel statistics.
     *
     * Returns:
     *   matched              int  — contacts passing stages 1 + 2 (scope + filter + includes + email hygiene)
     *   suppressed           int  — excluded by stage 3 (suppression list)
     *   duplicates_excluded  int  — duplicate emails collapsed by stage 4
     *   manually_excluded    int  — contacts removed by manual exclude pins (stage 5)
     *   manually_included    int  — pinned-in contacts that survived compliance (annotation, not a subtraction)
     *   final                int  — deliverable recipient count
     *   sample               array — [] unless $withSample; up to 10 rows
     *
     * Funnel identity:
     *   matched − suppressed − duplicates_excluded − manually_excluded === final
     *
     * Back-compat: existing callers pass ≤3 args ($scope, $filter, $withSample).
     * New callers may pass $includeIds / $excludeIds (arrays of contact IDs) and
     * $manualOnly to resolve those includes as an explicit allow-list.
     *
     * @param  string  $scope
     * @param  array   $filter
     * @param  bool    $withSample
     * @param  array   $includeIds   Contact IDs to force-include (union with filter, before compliance)
     * @param  array   $excludeIds   Contact IDs to force-exclude (after dedup)
     * @param  bool    $manualOnly   Resolve includes only; skip scope and JSON filter
     * @return array<string, mixed>
     */
    public function resolveWithStats(
        string $scope,
        array $filter,
        bool $withSample = false,
        array $includeIds = [],
        array $excludeIds = [],
        bool $manualOnly = false,
        string $policy = Campaign::VERIFICATION_VERIFIED_ONLY,
    ): array {
        // ── Count-only path (stages 1–4, no model hydration) ────────────────────
        $q12 = $this->buildBaseQuery($scope, $filter, hydrating: false, includeIds: $includeIds, manualOnly: $manualOnly);

        $q3 = (clone $q12);
        $this->applySuppressionStage($q3);

        // ── Stage counts (no model hydration) ─────────────────────────────────
        $matched  = $q12->count();
        $afterS3  = $q3->count();
        // Stage 4 dedup: COUNT(DISTINCT LOWER(TRIM(contacts.email)))
        $distinct = $q3->distinct()->count(DB::raw('LOWER(TRIM(contacts.email))'));

        $suppressed          = $matched - $afterS3;
        $duplicates_excluded = $afterS3 - $distinct;

        // ── Stage 5: exclude-after-dedup — use hydrated resolveCollection ───────
        // We need the actual resolved collection to correctly compute manually_excluded
        // (an exclude that lands on a duplicate would be double-counted otherwise).
        // For back-compat callers with no includeIds/excludeIds this path is cheap
        // (empty ids → resolveCollection result already correct).
        $postDedup = $this->buildPostDedupCollection($scope, $filter, $includeIds, $manualOnly);
        $qualityEligible = $this->filterByQuality($postDedup, $policy);
        $verification_excluded = $postDedup->count() - $qualityEligible->count();

        // Apply excludes: reject any contact whose id is in excludeIds.
        $preExclude = $qualityEligible->reject(fn (Contact $c) => in_array($c->id, $excludeIds, true));

        $manually_excluded = $qualityEligible->count() - $preExclude->count();
        $final             = $preExclude->count();
        $company_count     = $preExclude->pluck('company_id')->filter()->unique()->count();

        // ── manually_included: pinned-in contacts that survived compliance ──────
        // These are already counted inside $matched (they're in the union), so this
        // is an annotation only — not a subtraction from the funnel.
        $manually_included = empty($includeIds) ? 0 : $preExclude->filter(
            fn (Contact $c) => in_array($c->id, $includeIds, true)
        )->count();

        // ── Sample (optional, hydrated) ────────────────────────────────────────
        $sample = [];
        if ($withSample) {
            $sample = $preExclude
                ->take(10)
                ->map(fn (Contact $c) => [
                    'name'    => $c->name,
                    'company' => $c->company?->name ?? '',
                    'email'   => $c->email,
                ])
                ->values()
                ->all();
        }

        return compact(
            'matched',
            'suppressed',
            'duplicates_excluded',
            'verification_excluded',
            'manually_excluded',
            'manually_included',
            'final',
            'company_count',
            'sample',
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * The single hydrated source of truth for segment audience resolution.
     * Delegates to resolveAudience() — single code path, behavior preserved.
     *
     * @param  Segment  $segment
     * @return Collection<int, Contact>
     */
    private function resolveCollection(Segment $segment, string $policy): Collection
    {
        return $this->resolveAudience(
            $segment->scope,
            $segment->filter ?? [],
            $segment->includedContactIds(),
            $segment->excludedContactIds(),
            $segment->is_manual,
            $policy,
        );
    }

    /**
     * Build the eligible hydrated collection (stages 1–4 applied, dedup done,
     * excludes NOT yet subtracted). Shared between resolveCollection() and
     * resolveWithStats() so both derive counts from the same logic (N2).
     *
     * @param  string  $scope
     * @param  array   $filter
     * @param  array   $includeIds
     * @return Collection<int, Contact>
     */
    private function buildPostDedupCollection(string $scope, array $filter, array $includeIds, bool $manualOnly = false): Collection
    {
        $query = $this->buildBaseQuery($scope, $filter, hydrating: true, includeIds: $includeIds, manualOnly: $manualOnly);
        $this->applySuppressionStage($query);

        $contacts = $this->lifecycle->select($query)->get();

        // Stage 4: dedup by email (case-insensitive, application-level safety net).
        return $contacts
            ->unique(fn (Contact $c) => mb_strtolower(trim($c->email)))
            ->values();
    }

    /**
     * Apply only email-quality rules. The cold-send environment gate belongs
     * to the final send-time check and must never empty a segment preview.
     *
     * @param  Collection<int, Contact>  $contacts
     * @return Collection<int, Contact>
     */
    private function filterByQuality(Collection $contacts, string $policy): Collection
    {
        return $contacts
            ->reject(fn (Contact $contact): bool => $this->contactEligibility->audienceQualityReason($contact, $policy) !== null)
            ->values();
    }

    /**
     * Apply the scope predicate to a query builder.
     *
     * @param  Builder  $query
     * @param  string   $scope  One of: 'client', 'prospect', 'mixed'
     */
    private function applyScope(Builder $query, string $scope): void
    {
        if ($scope === 'client') {
            $query->whereHas('company', fn (Builder $q) => $q->where('relationship', 'client'));
        } elseif ($scope === 'prospect') {
            $query->whereHas('company', fn (Builder $q) => $q->where('relationship', 'prospect'));
        }
        // 'mixed' → no relationship filter
    }

    /**
     * Build the base query (stages 1 + 2): scope + JSON filter + email hygiene.
     *
     * When $includeIds is non-empty, the scope+filter predicate is wrapped in a
     * nested where() that also ORs in whereIn('contacts.id', $includeIds).
     * This ensures pinned-in contacts reach stages 3–4 even if they don't match
     * the scope/filter, while email-hygiene + whereHas('company') remain top-level
     * ANDs so ALL contacts (including includes) must have a valid email + company.
     *
     * @param  string  $scope      One of: 'client', 'prospect', 'mixed'
     * @param  array   $filter     Structured filter array
     * @param  bool    $hydrating  Whether the query will hydrate full models
     * @param  array   $includeIds Contact IDs to force-include (OR with scope+filter)
     * @param  bool    $manualOnly Resolve include IDs only; skip dynamic scope/filter
     * @return Builder
     */
    private function buildBaseQuery(string $scope, array $filter, bool $hydrating, array $includeIds = [], bool $manualOnly = false): Builder
    {
        $query = $hydrating
            ? Contact::with('company')->whereNotNull('email')
            : Contact::query()->whereNotNull('email');

        // D11: exclude empty / whitespace-only emails (top-level AND — applies to includes too).
        $query->whereRaw("TRIM(email) != ''");

        // All contacts must have an associated company (top-level AND).
        $query->whereHas('company');

        if ($manualOnly) {
            // A manual segment is an explicit allow-list: never union the dynamic
            // scope/filter audience. An empty selection must resolve to nobody.
            $query->whereIn('contacts.id', $includeIds ?: [0]);
        } elseif (! empty($includeIds)) {
            // Wrap (scope+filter) OR includeIds in a nested where() so the union
            // is an OR within the top-level AND chain (email hygiene + company stay outside).
            $query->where(function (Builder $nested) use ($scope, $filter, $includeIds) {
                // Scope + filter sub-predicate
                $nested->where(function (Builder $sf) use ($scope, $filter) {
                    $this->applyScope($sf, $scope);
                    if (! empty($filter)) {
                        $this->applyJsonFilter($sf, $filter);
                    }
                });
                // OR pinned includes
                $nested->orWhereIn('contacts.id', $includeIds);
            });
        } else {
            // Standard path: scope + filter as top-level ANDs.
            $this->applyScope($query, $scope);
            if (! empty($filter)) {
                $this->applyJsonFilter($query, $filter);
            }
        }

        return $query;
    }

    /**
     * Apply stage 3: suppression exclusion.
     *
     * B3 fix: normalized to LOWER(TRIM()) on both sides so whitespace-padded
     * emails (common in bulk-import pins) are correctly matched.
     * Pre-existing latent bug — exposed by the pin feature.
     *
     * Bindings are passed explicitly to avoid binding mismatch in Laravel's query builder.
     *
     * @param  Builder  $query  Modified in place.
     */
    private function applySuppressionStage(Builder $query): void
    {
        $sub = Suppression::select(DB::raw('LOWER(TRIM(email))'));
        $query->whereRaw(
            'LOWER(TRIM(contacts.email)) NOT IN (' . $sub->toSql() . ')',
            $sub->getBindings()
        );
    }

    /**
     * Apply a best-effort structured filter to the contact query.
     *
     * Supported keys:
     *   sector      → companies.sector      (exact match; scalar or array → where / whereIn)
     *   criteria_id → companies.criteria_id (exact match; scalar or array of ints)
     *   country     → companies.country     (exact match, 2-char ISO; scalar or array)
     *   lifecycle_state → calculated contact state (scalar or array)
     *
     * Boolean semantics inside the company whereHas:
     *   sector OR criteria_id  — when BOTH are present they are ORed with each other,
     *                            so a company matching either one qualifies. This lets a
     *                            segment target "these sectors, plus whatever this
     *                            discovery criteria found" in a single filter.
     *   AND country            — country is always ANDed with the sector/criteria group.
     *   When only one of sector / criteria_id is present it is applied directly (AND).
     *
     * Unknown keys are silently ignored to be defensive against future schema changes.
     * Multi-value upgrade (D3.4): arrays are passed as whereIn; scalars as where.
     * Empty values inside arrays are dropped via array_filter; a key whose cleaned
     * array is empty is treated as absent.
     *
     * @param  Builder               $query
     * @param  array<string, mixed>  $filter
     */
    private function applyJsonFilter(Builder $query, array $filter): void
    {
        $sector    = $this->cleanFilterValue($filter['sector'] ?? null);
        $criteria  = $this->cleanFilterValue($filter['criteria_id'] ?? null);
        $country   = $this->cleanFilterValue($filter['country'] ?? null);

        if ($sector !== null || $criteria !== null || $country !== null) {
            $query->whereHas('company', function (Builder $q) use ($sector, $criteria, $country) {
                // country is always ANDed.
                if ($country !== null) {
                    $this->applyColumnFilter($q, 'country', $country);
                }

                // sector OR criteria_id when both are present; otherwise plain AND.
                if ($sector !== null && $criteria !== null) {
                    $q->where(function (Builder $sub) use ($sector, $criteria) {
                        $this->applyColumnFilter($sub, 'sector', $sector, orMode: false);
                        $this->applyColumnFilter($sub, 'criteria_id', $criteria, orMode: true);
                    });
                } elseif ($sector !== null) {
                    $this->applyColumnFilter($q, 'sector', $sector);
                } elseif ($criteria !== null) {
                    $this->applyColumnFilter($q, 'criteria_id', $criteria);
                }
            });
        }

        if (! empty($filter['lifecycle_state'])) {
            $this->lifecycle->applyState($query, $filter['lifecycle_state']);
        }
    }

    /**
     * Normalise one raw filter value into either a scalar, a non-empty list, or null.
     *
     * Arrays are run through array_filter (dropping null / '') then re-indexed; an
     * array that cleans down to empty is treated as absent (null), as is any empty
     * scalar. Preserves the pre-existing `! empty()` semantics of the caller.
     *
     * @param  mixed  $value
     * @return mixed|null  Scalar, non-empty array, or null when the key is absent.
     */
    private function cleanFilterValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $cleaned = array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''));

            return empty($cleaned) ? null : $cleaned;
        }

        return empty($value) ? null : $value;
    }

    /**
     * Apply one cleaned company-column filter to $q as where / whereIn.
     *
     * @param  Builder       $q
     * @param  string        $column   Company column name.
     * @param  mixed         $value    Cleaned scalar or non-empty array.
     * @param  bool          $orMode   When true, use orWhere / orWhereIn instead of AND.
     */
    private function applyColumnFilter(Builder $q, string $column, mixed $value, bool $orMode = false): void
    {
        if (is_array($value)) {
            $orMode ? $q->orWhereIn($column, $value) : $q->whereIn($column, $value);
        } else {
            $orMode ? $q->orWhere($column, $value) : $q->where($column, $value);
        }
    }
}
