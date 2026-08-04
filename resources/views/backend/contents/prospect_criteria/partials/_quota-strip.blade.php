{{--
    Quota strip — render-ready quota meters shown ABOVE the criteria datatable.

    Replaces the old inline quota badge that lived in the .card-toolbar and
    overflowed the header row. All severity/colour math lives in
    DiscoveryQuotaService::displayMeters(); this partial only formats.

    Variables:
        $quotaMeters          array     displayMeters() output, keyed company|contacts (default [])
        $quotaPackage         ?Package  active package (default null)
        $activeDailyLimitSum  ?int      sum of daily_limit across ACTIVE criteria (default null)
        $providerSearchesLeft ?int      live search-provider account balance (default null)

    Renders nothing when $quotaMeters is empty (quota tables not yet migrated).
    The provider-balance card is independent of $quotaMeters — it is hidden
    (never shown as "0") whenever $providerSearchesLeft is null, which is the
    normal state on a `local`-driver dev box. Never names the provider — generic
    "crédits de découverte" per project convention.
--}}

@php
    $quotaMeters          = $quotaMeters          ?? [];
    $quotaPackage         = $quotaPackage         ?? null;
    $activeDailyLimitSum  = $activeDailyLimitSum  ?? null;
    $providerSearchesLeft = $providerSearchesLeft ?? null;
    $companyMeter = $quotaMeters['company'] ?? null;
    $fretiqDiscoveryAvailable = $companyMeter !== null
        && (($companyMeter['daily']['remaining'] ?? null) === null || $companyMeter['daily']['remaining'] > 0)
        && (($companyMeter['monthly']['remaining'] ?? null) === null || $companyMeter['monthly']['remaining'] > 0);
@endphp

@if($providerSearchesLeft !== null)
    <div class="row g-5 mb-6">
        <div class="col-md-6 col-xl-4">
            <x-crud.stat-card icon="bi-battery-charging"
                              color="info"
                              label="Capacité du fournisseur"
                              :value="$providerSearchesLeft" />
        </div>
    </div>
@endif

@if($providerSearchesLeft === 0 && $fretiqDiscoveryAvailable)
    <div class="alert alert-danger d-flex align-items-center mb-6" role="alert">
        <i class="bi bi-exclamation-octagon fs-2 me-3"></i>
        <div>
            <strong>Découverte bloquée par la capacité du fournisseur.</strong>
            Votre quota Fretiq reste disponible, mais aucune nouvelle recherche ne peut démarrer pour le moment.
        </div>
    </div>
@endif

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
                    $meterValue = 'Quota Fretiq — aujourd’hui : Quota package illimité · max. 20 par exécution';
                } else {
                    $meterValue = ($meter['unlimited'] ?? false)
                        ? 'Quota Fretiq — aujourd’hui : Illimité'
                        : 'Quota Fretiq — aujourd’hui : ' . $meterDaily['used_reserved'] . ' / ' . $meterDaily['total'];
                }

                // total === null is the unlimited signal (see displayMeterSummary()).
                $meterHint = $meterMonthly['total'] === null
                    ? 'Quota Fretiq — ce mois : Illimité'
                    : 'Quota Fretiq — ce mois : ' . $meterMonthly['used_reserved'] . ' / ' . $meterMonthly['total'];
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
