{{--
    Quota badge - daily + monthly quota display for SerpAPI searches and contacts.
    The numerator is conservative: consumed credits plus in-flight reservations.
--}}

@php
    $quotaRemaining          = $quotaRemaining          ?? null;
    $quotaPackage            = $quotaPackage            ?? null;
    $contactRemaining        = $contactRemaining        ?? null;
    $monthlyRemaining        = $monthlyRemaining        ?? null;
    $monthlyContactRemaining = $monthlyContactRemaining ?? null;
    $activeDailyLimitSum     = $activeDailyLimitSum     ?? null;
    $dailyQuotaSummary       = $dailyQuotaSummary       ?? [];
    $monthlyQuotaSummary     = $monthlyQuotaSummary     ?? [];

    $severity = function (?int $remaining, ?int $cap): int {
        if ($remaining === null) return 0;
        if ($remaining === 0) return 3;
        if ($cap > 0 && ($remaining / $cap) <= 0.20) return 2;
        return 0;
    };

    $severityClass = fn (int $s): string => match ($s) {
        3 => 'badge-light-danger',
        2 => 'badge-light-warning',
        default => 'badge-light-success',
    };

    $summary = function (array $source, string $meter, ?int $remaining, ?int $total): array {
        $row = $source[$meter] ?? [];
        $unlimited = $row['unlimited'] ?? ($remaining === null);
        $total = $row['total'] ?? $total;
        $remaining = $row['remaining'] ?? $remaining;

        return [
            'unlimited' => (bool) $unlimited,
            'used_reserved' => (int) ($row['used_reserved'] ?? (($total !== null && $remaining !== null) ? max(0, (int) $total - (int) $remaining) : 0)),
            'total' => $total,
            'remaining' => $remaining,
        ];
    };

    $formatMeter = function (array $daily, array $monthly): string {
        if ($daily['unlimited']) {
            $text = 'Utilisé + réservé : Illimité';
        } else {
            $text = 'Utilisé + réservé : ' . $daily['used_reserved'] . ' / ' . $daily['total']
                . ' - Restant : ' . $daily['remaining'] . ' /j';
        }

        if (! $monthly['unlimited']) {
            $text .= ' - Ce mois utilisé + réservé : ' . $monthly['used_reserved'] . ' / ' . $monthly['total']
                . ' - Restant : ' . $monthly['remaining'];
        }

        return $text;
    };

    $allUnlimited = $quotaRemaining === null
        && $contactRemaining === null
        && $monthlyRemaining === null
        && $monthlyContactRemaining === null;
@endphp

@if($allUnlimited)
    <span class="badge badge-light fs-7 fw-semibold">
        <i class="bi bi-infinity me-1"></i>
        {{ $quotaPackage?->name ?? 'Illimité' }} &middot; Illimité
    </span>
@else
    @php
        $companyDaily = $quotaPackage?->daily_credits ?? 0;
        $contactDaily = $quotaPackage?->daily_contact_credits ?? 0;

        $companyDailySummary = $summary($dailyQuotaSummary, 'company', $quotaRemaining, $companyDaily);
        $companyMonthlySummary = $summary($monthlyQuotaSummary, 'company', $monthlyRemaining, $quotaPackage?->monthly_credits);
        $contactDailySummary = $summary($dailyQuotaSummary, 'contacts', $contactRemaining, $contactDaily);
        $contactMonthlySummary = $summary($monthlyQuotaSummary, 'contacts', $monthlyContactRemaining, $quotaPackage?->monthly_contact_credits);

        $companyClass = $severityClass(max(
            $severity($quotaRemaining, $companyDaily),
            $severity($monthlyRemaining, $quotaPackage?->monthly_credits)
        ));
        $contactClass = $severityClass(max(
            $severity($contactRemaining, $contactDaily),
            $severity($monthlyContactRemaining, $quotaPackage?->monthly_contact_credits)
        ));
    @endphp

    <span class="badge {{ $companyClass }} fs-7 fw-semibold me-1">
        <i class="bi bi-building me-1"></i>
        Recherches SerpAPI : {{ $formatMeter($companyDailySummary, $companyMonthlySummary) }}
    </span>
    <span class="badge {{ $contactClass }} fs-7 fw-semibold">
        <i class="bi bi-person-lines-fill me-1"></i>
        Contacts : {{ $formatMeter($contactDailySummary, $contactMonthlySummary) }}
    </span>

    @if($quotaPackage?->daily_credits !== null && $activeDailyLimitSum > $quotaPackage->daily_credits)
        <span class="badge badge-light-warning fs-7 fw-semibold ms-1"
              data-bs-toggle="tooltip"
              title="La somme des recherches SerpAPI/jour des critères actifs ({{ $activeDailyLimitSum }}) dépasse le quota du package ({{ $quotaPackage->daily_credits }}/j). C'est une priorité, pas une réservation garantie : le premier critère lancé consomme le quota disponible, les autres attendent.">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Sur-reservation priorisee &middot; {{ $activeDailyLimitSum }}/{{ $quotaPackage->daily_credits }} /j
        </span>
    @endif
@endif
