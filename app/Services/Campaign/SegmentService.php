<?php

namespace App\Services\Campaign;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
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
 *   4. Cold gate: if config('prospecting.cold_send_enabled') is false,
 *      exclude contacts whose company.relationship = 'prospect'.
 *   5. Cold email kind: when cold gate IS open, exclude email_kind = 'personal'.
 *   6. Deduplicate by email (application-level, case-insensitive on trimmed email).
 *   7. Subtract manual excludes (after dedup — exclude always wins).
 *
 * Merge semantics (hybrid smart-list):
 *   Final audience = pipeline( (filter_matches ∪ manual_includes) − manual_excludes )
 *
 *   Includes: UNIONed at scope+filter boundary (stage 1+2), BEFORE compliance.
 *     A pinned-in contact bypasses scope+filter only — faces stages 3–7 unchanged.
 *   Excludes: subtracted AFTER dedup (stage 7), unconditionally.
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
    /**
     * Resolve the audience for a segment, with all compliance filters applied.
     * This is the single entry point for the send-path and the contacts table.
     *
     * @return Collection<int, Contact>  Eligible Contact models (with 'company' relation loaded).
     */
    public function resolve(Segment $segment): Collection
    {
        return $this->resolveCollection($segment);
    }

    /**
     * Return the count of eligible contacts for a segment.
     * Delegates to resolveWithStats for a truthful, pipeline-consistent count.
     */
    public function previewCount(Segment $segment): int
    {
        return $this->resolveWithStats($segment->scope, $segment->filter ?? [])['final'];
    }

    /**
     * Run the full compliance pipeline and return per-stage funnel statistics.
     *
     * Returns:
     *   matched              int  — contacts passing stages 1 + 2 (scope + filter + includes + email hygiene)
     *   suppressed           int  — excluded by stage 3 (suppression list)
     *   cold_excluded        int  — excluded by stage 4 (cold gate)
     *   personal_excluded    int  — excluded by stage 5 (email-kind personal when gate open)
     *   duplicates_excluded  int  — duplicate emails collapsed by stage 6
     *   manually_excluded    int  — contacts removed by manual exclude pins (stage 7)
     *   manually_included    int  — pinned-in contacts that survived compliance (annotation, not a subtraction)
     *   final                int  — deliverable recipient count
     *   sample               array — [] unless $withSample; up to 10 rows
     *
     * Funnel identity:
     *   matched − suppressed − cold_excluded − personal_excluded − duplicates_excluded − manually_excluded === final
     *
     * Back-compat: existing callers pass ≤3 args ($scope, $filter, $withSample).
     * New callers may pass $includeIds / $excludeIds (arrays of contact IDs).
     *
     * @param  string  $scope
     * @param  array   $filter
     * @param  bool    $withSample
     * @param  array   $includeIds   Contact IDs to force-include (union with filter, before compliance)
     * @param  array   $excludeIds   Contact IDs to force-exclude (after dedup)
     * @return array<string, mixed>
     */
    public function resolveWithStats(
        string $scope,
        array $filter,
        bool $withSample = false,
        array $includeIds = [],
        array $excludeIds = [],
    ): array {
        // ── Count-only path (stages 1–6, no model hydration) ────────────────────
        $q12 = $this->buildBaseQuery($scope, $filter, hydrating: false, includeIds: $includeIds);

        $q3 = (clone $q12);
        $this->applySuppressionStage($q3);

        $q4 = (clone $q3);
        $this->applyColdGateStage($q4);

        $q5 = (clone $q4);
        $this->applyEmailKindStage($q5);

        // ── Stage counts (no model hydration) ─────────────────────────────────
        $matched  = $q12->count();
        $afterS3  = $q3->count();
        $afterS4  = $q4->count();
        $afterS5  = $q5->count();

        // Stage 6 dedup: COUNT(DISTINCT LOWER(TRIM(contacts.email)))
        $distinct = $q5->distinct()->count(DB::raw('LOWER(TRIM(contacts.email))'));

        $suppressed          = $matched - $afterS3;
        $cold_excluded       = $afterS3 - $afterS4;
        $personal_excluded   = $afterS4 - $afterS5;
        $duplicates_excluded = $afterS5 - $distinct;

        // ── Stage 7: exclude-after-dedup — use hydrated resolveCollection ───────
        // We need the actual resolved collection to correctly compute manually_excluded
        // (an exclude that lands on a duplicate would be double-counted otherwise).
        // For back-compat callers with no includeIds/excludeIds this path is cheap
        // (empty ids → resolveCollection result already correct).
        $postDedup = $this->buildStage5HydratedCollection($scope, $filter, $includeIds);

        // Apply excludes: reject any contact whose id is in excludeIds.
        $preExclude = $postDedup->reject(fn (Contact $c) => in_array($c->id, $excludeIds, true));

        $manually_excluded = $postDedup->count() - $preExclude->count();
        $final             = $preExclude->count();

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
            'cold_excluded',
            'personal_excluded',
            'duplicates_excluded',
            'manually_excluded',
            'manually_included',
            'final',
            'sample',
        );
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * The single hydrated source of truth for segment audience resolution.
     * Builds the post-dedup, post-exclude collection that resolve() returns.
     *
     * @param  Segment  $segment
     * @return Collection<int, Contact>
     */
    private function resolveCollection(Segment $segment): Collection
    {
        $includeIds = $segment->includedContactIds();
        $excludeIds = $segment->excludedContactIds();

        $postDedup = $this->buildStage5HydratedCollection(
            $segment->scope,
            $segment->filter ?? [],
            $includeIds,
        );

        // Stage 7: subtract excludes unconditionally after dedup.
        // Exclude wins — an excluded contact cannot hold a dedup slot.
        return $postDedup
            ->reject(fn (Contact $c) => in_array($c->id, $excludeIds, true))
            ->values();
    }

    /**
     * Build the stage-5-hydrated collection (stages 1–6 applied, dedup done,
     * excludes NOT yet subtracted). Shared between resolveCollection() and
     * resolveWithStats() so both derive counts from the same logic (N2).
     *
     * @param  string  $scope
     * @param  array   $filter
     * @param  array   $includeIds
     * @return Collection<int, Contact>
     */
    private function buildStage5HydratedCollection(string $scope, array $filter, array $includeIds): Collection
    {
        $query = $this->buildBaseQuery($scope, $filter, hydrating: true, includeIds: $includeIds);
        $this->applySuppressionStage($query);
        $this->applyColdGateStage($query);
        $this->applyEmailKindStage($query);

        $contacts = $query->get();

        // Stage 6: dedup by email (case-insensitive, application-level safety net).
        return $contacts
            ->unique(fn (Contact $c) => mb_strtolower(trim($c->email)))
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
     * This ensures pinned-in contacts reach stages 3–6 even if they don't match
     * the scope/filter, while email-hygiene + whereHas('company') remain top-level
     * ANDs so ALL contacts (including includes) must have a valid email + company.
     *
     * @param  string  $scope      One of: 'client', 'prospect', 'mixed'
     * @param  array   $filter     Structured filter array
     * @param  bool    $hydrating  Whether the query will hydrate full models
     * @param  array   $includeIds Contact IDs to force-include (OR with scope+filter)
     * @return Builder
     */
    private function buildBaseQuery(string $scope, array $filter, bool $hydrating, array $includeIds = []): Builder
    {
        $query = $hydrating
            ? Contact::with('company')->whereNotNull('email')
            : Contact::query()->whereNotNull('email');

        // D11: exclude empty / whitespace-only emails (top-level AND — applies to includes too).
        $query->whereRaw("TRIM(email) != ''");

        // All contacts must have an associated company (top-level AND).
        $query->whereHas('company');

        if (! empty($includeIds)) {
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
     * Apply stage 4: cold gate.
     * When cold sending is disabled, exclude all prospect contacts.
     *
     * @param  Builder  $query  Modified in place.
     */
    private function applyColdGateStage(Builder $query): void
    {
        $coldEnabled = (bool) config('prospecting.cold_send_enabled', false);

        if (! $coldEnabled) {
            $query->whereHas('company', fn (Builder $q) => $q->where('relationship', '!=', 'prospect'));
        }
    }

    /**
     * Apply stage 5: cold email-kind exclusion.
     * Only relevant when the cold gate is OPEN.
     * Personal emails must be excluded from cold outreach (CNIL B2B legitimate interest basis).
     *
     * @param  Builder  $query  Modified in place.
     */
    private function applyEmailKindStage(Builder $query): void
    {
        $coldEnabled = (bool) config('prospecting.cold_send_enabled', false);

        if ($coldEnabled) {
            $query->where(function (Builder $q) {
                // Clients: no restriction.
                // Prospects (cold): only role-based / null emails allowed.
                $q->whereHas('company', fn (Builder $cq) => $cq->where('relationship', 'client'))
                  ->orWhere(function (Builder $inner) {
                      $inner->whereHas('company', fn (Builder $cq) => $cq->where('relationship', 'prospect'))
                            ->where(fn (Builder $c) => $c->where('email_kind', '!=', 'personal')
                                                          ->orWhereNull('email_kind'));
                  });
            });
        }
    }

    /**
     * Build a stage-5 query (all stages applied, ready for hydration or count).
     * Back-compat convenience wrapper — kept for internal use.
     *
     * @deprecated Use buildStage5HydratedCollection() or buildBaseQuery() directly.
     */
    private function buildStage5Query(string $scope, array $filter, bool $hydrating): Builder
    {
        $query = $this->buildBaseQuery($scope, $filter, hydrating: $hydrating);
        $this->applySuppressionStage($query);
        $this->applyColdGateStage($query);
        $this->applyEmailKindStage($query);

        return $query;
    }

    /**
     * Apply a best-effort structured filter to the contact query.
     *
     * Supported keys:
     *   sector   → companies.sector  (exact match; scalar or array → where / whereIn)
     *   country  → companies.country (exact match, 2-char ISO; scalar or array)
     *   status   → contacts.status   (exact match; scalar only)
     *
     * Unknown keys are silently ignored to be defensive against future schema changes.
     * Multi-value upgrade (D3.4): arrays are passed as whereIn; scalars as where.
     * Empty values inside arrays are dropped via array_filter.
     *
     * @param  Builder               $query
     * @param  array<string, mixed>  $filter
     */
    private function applyJsonFilter(Builder $query, array $filter): void
    {
        $companyFilters = [];

        if (! empty($filter['sector'])) {
            $companyFilters['sector'] = $filter['sector'];
        }

        if (! empty($filter['country'])) {
            $companyFilters['country'] = $filter['country'];
        }

        if (! empty($companyFilters)) {
            $query->whereHas('company', function (Builder $q) use ($companyFilters) {
                foreach ($companyFilters as $column => $value) {
                    if (is_array($value)) {
                        // Drop empty strings / nulls inside the array.
                        $cleaned = array_values(array_filter($value, fn ($v) => $v !== null && $v !== ''));
                        if (! empty($cleaned)) {
                            $q->whereIn($column, $cleaned);
                        }
                    } else {
                        $q->where($column, $value);
                    }
                }
            });
        }

        if (! empty($filter['status'])) {
            // status is always a scalar — plain string match on contacts.status.
            $query->where('status', $filter['status']);
        }
    }
}
