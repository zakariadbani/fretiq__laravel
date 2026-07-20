{{--
    Quota strip — render-ready quota meters shown ABOVE the criteria datatable.

    Replaces the old inline quota badge that lived in the .card-toolbar and
    overflowed the header row. All severity/colour math lives in
    DiscoveryQuotaService::displayMeters(); this partial only formats.

    Variables:
        $quotaMeters         array     displayMeters() output, keyed company|contacts (default [])
        $quotaPackage        ?Package  active package (default null)
        $activeDailyLimitSum ?int      sum of daily_limit across ACTIVE criteria (default null)

    Renders nothing when $quotaMeters is empty (quota tables not yet migrated).
--}}

@php
    $quotaMeters         = $quotaMeters         ?? [];
    $quotaPackage        = $quotaPackage        ?? null;
    $activeDailyLimitSum = $activeDailyLimitSum ?? null;
@endphp

@if(! empty($quotaMeters))

    {{-- Over-reservation banner: active criteria collectively ask for more than the package allows. --}}
    @if($quotaPackage?->daily_credits !== null && $activeDailyLimitSum > $quotaPackage->daily_credits)
        <div class="alert alert-warning d-flex align-items-center mb-6">
            <i class="bi bi-exclamation-triangle fs-2 me-3"></i>
            <div>
                <span class="fw-semibold">Sur-réservation priorisée &middot; {{ $activeDailyLimitSum }}/{{ $quotaPackage->daily_credits }} par jour</span>
                — la somme des recherches d’entreprises/jour des critères actifs dépasse le quota du package.
                C'est une priorité, pas une réservation garantie : le premier critère lancé consomme le quota
                disponible, les autres attendent.
            </div>
        </div>
    @endif

    <div class="row g-5 mb-6">
        @foreach($quotaMeters as $meterKey => $meter)
            @php
                $meterDaily   = $meter['daily']   ?? ['used_reserved' => 0, 'total' => null];
                $meterMonthly = $meter['monthly'] ?? ['used_reserved' => 0, 'total' => null];
                $meterLabel = $meterKey === 'contacts'
                    ? 'Tentatives d’enrichissement'
                    : 'Recherches d’entreprises';

                if (($meter['unlimited'] ?? false) && $meterKey === 'contacts') {
                    $meterValue = 'Quota package illimité · max. 20 par exécution';
                } else {
                    $meterValue = ($meter['unlimited'] ?? false)
                        ? 'Illimité'
                        : $meterDaily['used_reserved'] . ' / ' . $meterDaily['total'] . ' par jour';
                }

                // total === null is the unlimited signal (see displayMeterSummary()).
                $meterHint = $meterMonthly['total'] === null
                    ? null
                    : $meterMonthly['used_reserved'] . ' / ' . $meterMonthly['total'] . ' ce mois';
            @endphp

            <div class="col-md-6 col-xl-4">
                <x-crud.stat-card :icon="$meter['icon'] ?? null"
                                  :color="$meter['color'] ?? 'success'"
                                  :label="$meterLabel"
                                  :value="$meterValue"
                                  :hint="$meterHint" />
            </div>
        @endforeach
    </div>

@endif
