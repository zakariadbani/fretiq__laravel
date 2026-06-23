{{--
    Quota badge — shows daily + monthly solde for both meters (découvertes + contacts).

    Variables (injected by the including template):
        $quotaRemaining          ?int   — null when unlimited, int >= 0 when limited (company/discovery meter, daily)
        $quotaPackage            ?Package — the active Package model, or null (= no assignment = unlimited)
        $contactRemaining        ?int   — null when unlimited, int >= 0 when limited (contact/enrichment meter, daily)
        $monthlyRemaining        ?int   — null when no monthly cap, int >= 0 when monthly-limited (company meter)
        $monthlyContactRemaining ?int   — null when no monthly cap, int >= 0 when monthly-limited (contact meter)

    Renders a compact two-meter badge. Gracefully defaults optional vars to null.
--}}

@php
    $contactRemaining        = $contactRemaining        ?? null;
    $monthlyRemaining        = $monthlyRemaining        ?? null;
    $monthlyContactRemaining = $monthlyContactRemaining ?? null;

    /**
     * Severity helper — returns 0 (success), 2 (warning), or 3 (danger).
     * NEVER divides unless $cap > 0 (a cap of 0 is valid and means danger).
     *
     * @param int|null $remaining  null = unlimited → 0 (success)
     * @param int|null $cap        the matching credit limit column value
     * @return int
     */
    $severity = function (?int $remaining, ?int $cap): int {
        if ($remaining === null) return 0;          // unlimited → success
        if ($remaining === 0)   return 3;           // exhausted → danger
        if ($cap > 0 && ($remaining / $cap) <= 0.20) return 2;  // ≤20% → warning (guard: $cap > 0)
        return 0;
    };

    $severityClass = function (int $s): string {
        return match($s) {
            3 => 'badge-light-danger',
            2 => 'badge-light-warning',
            default => 'badge-light-success',
        };
    };
@endphp

@if($quotaRemaining === null && $contactRemaining === null && $monthlyRemaining === null && $monthlyContactRemaining === null)
    {{-- All unlimited --}}
    @php
        $packLabel = $quotaPackage?->name ?? 'Illimité';
    @endphp
    <span class="badge badge-light fs-7 fw-semibold">
        <i class="bi bi-infinity me-1"></i>
        {{ $packLabel }} &middot; Illimité
    </span>
@else
    @php
        // ── Company meter ─────────────────────────────────────────────────────
        $companyDaily = $quotaPackage?->daily_credits ?? 0;

        if ($quotaRemaining === null) {
            $companyDailyText = '∞ Illimité';
        } elseif ($quotaRemaining === 0) {
            $companyDailyText = '0 / ' . $companyDaily;
        } else {
            $companyDailyText = $quotaRemaining . ' / ' . $companyDaily;
        }

        // Monthly suffix for company meter
        if ($monthlyRemaining !== null) {
            $monthlyCap = $quotaPackage?->monthly_credits ?? 0;
            $companyText = $companyDailyText . ' · ' . $monthlyRemaining . ' / ' . $monthlyCap . ' ce mois';
        } else {
            $companyText = $companyDailyText;
        }

        // Worst severity of daily vs monthly for the company badge
        $companySev   = max(
            $severity($quotaRemaining,   $companyDaily),
            $severity($monthlyRemaining, $quotaPackage?->monthly_credits)
        );
        $companyClass = $severityClass($companySev);

        // ── Contact meter ─────────────────────────────────────────────────────
        $contactDaily = $quotaPackage?->daily_contact_credits ?? 0;

        if ($contactRemaining === null) {
            $contactDailyText = '∞ Illimité';
        } elseif ($contactRemaining === 0) {
            $contactDailyText = '0 / ' . $contactDaily;
        } else {
            $contactDailyText = $contactRemaining . ' / ' . $contactDaily;
        }

        // Monthly suffix for contact meter
        if ($monthlyContactRemaining !== null) {
            $monthlyContactCap = $quotaPackage?->monthly_contact_credits ?? 0;
            $contactText = $contactDailyText . ' · ' . $monthlyContactRemaining . ' / ' . $monthlyContactCap . ' ce mois';
        } else {
            $contactText = $contactDailyText;
        }

        // Worst severity of daily vs monthly for the contact badge
        $contactSev   = max(
            $severity($contactRemaining,        $contactDaily),
            $severity($monthlyContactRemaining, $quotaPackage?->monthly_contact_credits)
        );
        $contactClass = $severityClass($contactSev);

        $packName = $quotaPackage?->name ?? 'Pack';
    @endphp
    <span class="badge {{ $companyClass }} fs-7 fw-semibold me-1">
        <i class="bi bi-building me-1"></i>
        Découvertes : {{ $companyText }}
    </span>
    <span class="badge {{ $contactClass }} fs-7 fw-semibold">
        <i class="bi bi-person-lines-fill me-1"></i>
        Contacts : {{ $contactText }}
    </span>
@endif
