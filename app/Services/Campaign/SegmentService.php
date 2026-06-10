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
 *   3. Suppression exclusion: Suppression table subquery.
 *   4. Cold gate: if config('prospecting.cold_send_enabled') is false,
 *      exclude contacts whose company.relationship = 'prospect'.
 *   5. Cold email kind: when cold gate IS open, exclude email_kind = 'personal'.
 *   6. Deduplicate by email.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * BEHAVIOR CHANGES (2026-06-10, D11):
 *   (a) Empty / whitespace-only emails are now excluded from all paths.
 *       Previously a contact with email='' or email='   ' would be included
 *       in the base query (whereNotNull passes). Now excluded via
 *       whereRaw("TRIM(email) != ''").
 *   (b) Deduplication is now case-insensitive on the trimmed email.
 *       Previously unique('email') compared raw bytes, so 'Alice@Corp.com' and
 *       'alice@corp.com' were counted as two distinct contacts. Now both
 *       collapse to the same slot via mb_strtolower(trim($email)).
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @see compliance-deliverability.md §1, §2
 * @see campaign-automation.md §5
 */
class SegmentService
{
    /**
     * Resolve the audience for a segment, with all compliance filters applied.
     *
     * @return Collection<int, Contact>  Eligible Contact models (with 'company' relation loaded).
     */
    public function resolve(Segment $segment): Collection
    {
        $query = $this->buildStage5Query(
            $segment->scope,
            $segment->filter ?? [],
            hydrating: true,
        );

        $contacts = $query->get();

        // ── 6. Deduplicate by email (case-insensitive, application-level safety net) ──
        // D11: dedup key is now mb_strtolower(trim($email)) instead of raw email.
        return $contacts
            ->unique(fn (Contact $c) => mb_strtolower(trim($c->email)))
            ->values();
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
     * Run the full compliance pipeline in count-only mode (no model hydration)
     * and return per-stage funnel statistics.
     *
     * Returns:
     *   matched              int  — contacts passing stages 1 + 2 (scope + filter + email hygiene)
     *   suppressed           int  — excluded by stage 3 (suppression list)
     *   cold_excluded        int  — excluded by stage 4 (cold gate)
     *   personal_excluded    int  — excluded by stage 5 (email-kind personal when gate open)
     *   duplicates_excluded  int  — duplicate emails collapsed by stage 6
     *   final                int  — deliverable recipient count
     *   sample               array — [] unless $withSample; up to 10 rows:
     *                                [['name' => …, 'company' => …, 'email' => …], …]
     *
     * Funnel identity (always holds by construction):
     *   matched − suppressed − cold_excluded − personal_excluded − duplicates_excluded === final
     *
     * Per D5/D12 threshold: per-row COUNT(*) queries are acceptable at current scale.
     * Revisit with caching (segments.last_built_at as cache timestamp) when
     * >50 segments or >50k contacts.
     *
     * @param  string  $scope       One of: 'client', 'prospect', 'mixed'
     * @param  array   $filter      Structured filter array (keys: sector, country, status)
     * @param  bool    $withSample  When true, hydrate and return up to 10 sample contacts
     * @return array<string, mixed>
     */
    public function resolveWithStats(string $scope, array $filter, bool $withSample = false): array
    {
        // Build the progressive query chain.
        // Each stage clones the previous so they remain independent.
        $q12 = $this->buildBaseQuery($scope, $filter, hydrating: false);

        $q3 = (clone $q12);
        $this->applySuppressionStage($q3);

        $q4 = (clone $q3);
        $this->applyColdGateStage($q4);

        $q5 = (clone $q4);
        $this->applyEmailKindStage($q5);

        // ── Stage counts (no model hydration) ─────────────────────────────────
        $matched = $q12->count();
        $afterS3 = $q3->count();
        $afterS4 = $q4->count();
        $afterS5 = $q5->count();

        // Stage 6 dedup: COUNT(DISTINCT LOWER(TRIM(contacts.email)))
        $distinct = $q5->distinct()->count(DB::raw('LOWER(TRIM(contacts.email))'));

        $suppressed         = $matched - $afterS3;
        $cold_excluded      = $afterS3 - $afterS4;
        $personal_excluded  = $afterS4 - $afterS5;
        $duplicates_excluded = $afterS5 - $distinct;
        $final              = $distinct;

        // ── Sample (optional, hydrated) ────────────────────────────────────────
        $sample = [];
        if ($withSample) {
            $sampleQuery = $this->buildBaseQuery($scope, $filter, hydrating: true);
            $this->applySuppressionStage($sampleQuery);
            $this->applyColdGateStage($sampleQuery);
            $this->applyEmailKindStage($sampleQuery);

            $sampleContacts = $sampleQuery
                ->orderBy('contacts.id')
                ->limit(30)
                ->get();

            // Dedup in PHP (case-insensitive on trimmed email), take first 10
            $sample = $sampleContacts
                ->unique(fn (Contact $c) => mb_strtolower(trim($c->email)))
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
            'final',
            'sample',
        );
    }

    // ── Private stage builders ─────────────────────────────────────────────────

    /**
     * Build the base query (stages 1 + 2): scope + JSON filter + email hygiene.
     *
     * When $hydrating is true, eager-loads the 'company' relation.
     * When false (count mode), skips the eager load to avoid unnecessary joins.
     *
     * @param  string  $scope      One of: 'client', 'prospect', 'mixed'
     * @param  array   $filter     Structured filter array
     * @param  bool    $hydrating  Whether the query will hydrate full models
     * @return Builder
     */
    private function buildBaseQuery(string $scope, array $filter, bool $hydrating): Builder
    {
        $query = $hydrating
            ? Contact::with('company')->whereNotNull('email')
            : Contact::query()->whereNotNull('email');

        // D11: exclude empty / whitespace-only emails.
        $query->whereRaw("TRIM(email) != ''");

        // All contacts must have an associated company.
        $query->whereHas('company');

        // ── 1. Scope filter ────────────────────────────────────────────────────
        if ($scope === 'client') {
            $query->whereHas('company', fn (Builder $q) => $q->where('relationship', 'client'));
        } elseif ($scope === 'prospect') {
            $query->whereHas('company', fn (Builder $q) => $q->where('relationship', 'prospect'));
        }
        // 'mixed' → no relationship filter

        // ── 2. JSON filter (best-effort, defensive) ────────────────────────────
        if (! empty($filter)) {
            $this->applyJsonFilter($query, $filter);
        }

        return $query;
    }

    /**
     * Apply stage 3: suppression exclusion.
     * Contacts whose email appears in the suppressions table are removed.
     *
     * Note: suppressions store lowercase emails. Current semantics use a direct
     * whereNotIn subquery (exact-match). Case-normalisation of the match is NOT
     * changed in this pass (not in approved scope D11).
     *
     * @param  Builder  $query  Modified in place.
     */
    private function applySuppressionStage(Builder $query): void
    {
        $query->whereNotIn('email', Suppression::select('email'));
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
     *
     * Convenience wrapper used by resolve().
     *
     * @param  string  $scope
     * @param  array   $filter
     * @param  bool    $hydrating
     * @return Builder
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
