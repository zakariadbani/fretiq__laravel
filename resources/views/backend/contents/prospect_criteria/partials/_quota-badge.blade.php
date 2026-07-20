{{--
    Compact quota badges for the ProspectCriteria view-page hero action row.

    All severity/colour math now lives in DiscoveryQuotaService::displayMeters() —
    this partial only formats. The over-reservation warning moved to the index
    quota strip (_quota-strip.blade.php), where it has room for a real sentence.

    The numerator stays conservative: consumed credits plus in-flight reservations.

    Variables:
        $quotaMeters  array  displayMeters() output, keyed company|contacts (default [])

    Renders nothing when $quotaMeters is empty (quota tables not yet migrated).
--}}

@php
    $quotaMeters = $quotaMeters ?? [];

    // Hero row is tight — short labels only; the strip carries the full wording.
    $quotaShortLabels = [
        'company'  => 'Recherches d’entreprises',
        'contacts' => 'Tentatives d’enrichissement',
    ];
@endphp

@foreach($quotaMeters as $meterKey => $meter)
    @php
        $meterDaily   = $meter['daily']   ?? ['used_reserved' => 0, 'total' => null];
        $meterMonthly = $meter['monthly'] ?? ['used_reserved' => 0, 'total' => null];

        $meterText = ($quotaShortLabels[$meterKey] ?? ($meter['label'] ?? '')) . ' ';

        if (($meter['unlimited'] ?? false) && $meterKey === 'contacts') {
            $meterText .= 'quota package ∞ · max. 20/exéc.';
        } else {
            $meterText .= ($meter['unlimited'] ?? false)
                ? 'Illimité'
                : $meterDaily['used_reserved'] . '/' . $meterDaily['total'] . ' j';
        }

        // total === null is the unlimited signal (see displayMeterSummary()).
        if ($meterMonthly['total'] !== null) {
            $meterText .= ' · ' . $meterMonthly['used_reserved'] . '/' . $meterMonthly['total'] . ' m';
        }
    @endphp
    <span class="badge badge-light-{{ $meter['color'] ?? 'success' }} fs-7 fw-semibold me-1">
        <i class="bi {{ $meter['icon'] ?? 'bi-speedometer2' }} me-1"></i>{{ $meterText }}
    </span>
@endforeach
