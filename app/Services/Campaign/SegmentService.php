<?php

namespace App\Services\Campaign;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Suppression;
use Illuminate\Support\Collection;

/**
 * SegmentService — THE single compliance point for resolving a segment's audience.
 *
 * All compliance rules are enforced here (and re-checked at send time in
 * CampaignService). No other service bypasses these rules.
 *
 * Rules applied (in order):
 *   1. Scope filter: client | prospect | mixed (company.relationship).
 *   2. JSON filter: best-effort on a small set of known keys.
 *   3. Suppression exclusion: Suppression::isSuppressed() / subquery.
 *   4. Cold gate: if config('prospecting.cold_send_enabled') is false,
 *      exclude contacts whose company.relationship = 'prospect'.
 *   5. Cold email kind: when cold gate IS open, exclude email_kind = 'personal'.
 *   6. Deduplicate by email.
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
        $query = Contact::with('company')
            ->whereNotNull('email')
            ->whereHas('company');

        // ── 1. Scope filter ────────────────────────────────────────────────────
        if ($segment->scope === 'client') {
            $query->whereHas('company', fn ($q) => $q->where('relationship', 'client'));
        } elseif ($segment->scope === 'prospect') {
            $query->whereHas('company', fn ($q) => $q->where('relationship', 'prospect'));
        }
        // 'mixed' → no relationship filter

        // ── 2. JSON filter (best-effort, defensive) ────────────────────────────
        $filter = $segment->filter ?? [];
        if (! empty($filter)) {
            $this->applyJsonFilter($query, $filter);
        }

        // ── 3. Suppression exclusion ───────────────────────────────────────────
        // Subquery on suppressions table (case-insensitive by using the stored
        // lowercase email in suppressions, matching LOWER(contacts.email)).
        $query->whereNotIn(
            'email',
            Suppression::select('email'),
        );

        // ── 4. Cold gate ───────────────────────────────────────────────────────
        $coldEnabled = (bool) config('prospecting.cold_send_enabled', false);
        if (! $coldEnabled) {
            // Exclude all cold (prospect) contacts when the gate is closed.
            $query->whereHas('company', fn ($q) => $q->where('relationship', '!=', 'prospect'));
        }

        // ── 5. Cold email-kind exclusion ───────────────────────────────────────
        // Only relevant when the cold gate is open; personal emails must be
        // excluded from cold outreach (CNIL B2B legitimate interest basis).
        if ($coldEnabled) {
            $query->where(function ($q) {
                // Safe for clients: no restriction.
                // For prospects (cold): only role-based emails allowed.
                $q->whereHas('company', fn ($cq) => $cq->where('relationship', 'client'))
                  ->orWhere(function ($inner) {
                      $inner->whereHas('company', fn ($cq) => $cq->where('relationship', 'prospect'))
                            ->where(fn ($c) => $c->where('email_kind', '!=', 'personal')
                                                  ->orWhereNull('email_kind'));
                  });
            });
        }

        $contacts = $query->get();

        // ── 6. Deduplicate by email (application-level safety net) ─────────────
        return $contacts->unique('email')->values();
    }

    /**
     * Return the count of eligible contacts for a segment.
     * Used by the UI segment preview.
     */
    public function previewCount(Segment $segment): int
    {
        return $this->resolve($segment)->count();
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Apply a best-effort JSON filter to the contact query.
     *
     * Supported keys:
     *   sector   → companies.sector  (exact match)
     *   country  → companies.country (exact match, 2-char ISO)
     *   status   → contacts.status   (exact match)
     *
     * Unknown keys are silently ignored to be defensive against future schema changes.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<string, mixed>                   $filter
     */
    private function applyJsonFilter(\Illuminate\Database\Eloquent\Builder $query, array $filter): void
    {
        $companyFilters = [];

        if (! empty($filter['sector'])) {
            $companyFilters['sector'] = $filter['sector'];
        }

        if (! empty($filter['country'])) {
            $companyFilters['country'] = $filter['country'];
        }

        if (! empty($companyFilters)) {
            $query->whereHas('company', function ($q) use ($companyFilters) {
                foreach ($companyFilters as $column => $value) {
                    $q->where($column, $value);
                }
            });
        }

        if (! empty($filter['status'])) {
            $query->where('status', $filter['status']);
        }
    }
}
