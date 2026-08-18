<x-default-layout>

@section('title')
    Quota fournisseurs
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Quota fournisseurs']]" />
@endsection

@php
    // Local bar styling for provider (SerpAPI / Hunter) figures — unrelated to the severity
    // rule in DiscoveryQuotaService::displayMeters(), which only covers the internal package
    // credit ledger. These used/total values come from the providers' own account endpoints,
    // so there is no meter, cap or `remaining` from the service to reuse here.
    // Never divides unless $cap > 0 — a cap of 0 is valid and means danger.
    $barClass = function ($used, $cap): string {
        $cap  = ($cap  === null) ? null : (int) $cap;
        $used = ($used === null) ? 0    : (int) $used;
        if ($cap === null) return 'bg-success';
        if ($cap <= 0)     return 'bg-danger';
        $ratio = $used / $cap;
        if ($ratio >= 1)   return 'bg-danger';
        if ($ratio >= 0.8) return 'bg-warning';
        return 'bg-primary';
    };
    $pct = function ($used, $cap): int {
        $cap  = ($cap  === null) ? null : (int) $cap;
        $used = ($used === null) ? 0    : (int) $used;
        if ($cap === null || $cap <= 0) return 0;
        return (int) min(100, round(($used / $cap) * 100));
    };
    $quotaValue = function ($value, $total): string {
        if ($value === null) return '—';
        return max(0, (int) $value).' / '.($total === null ? '—' : max(0, (int) $total));
    };
@endphp

@if($driverLive === false)
    <div class="alert alert-info d-flex align-items-center p-5 mb-6">
        <i class="bi bi-info-circle fs-2 text-info me-3"></i>
        <div class="text-gray-700">Mode local actif — données fournisseurs indisponibles.</div>
    </div>
@endif

<div class="d-flex justify-content-end mb-4">
    <form method="POST" action="{{ route('admin.provider-quota.refresh') }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-light-primary">
            <i class="bi bi-arrow-clockwise me-1"></i> Actualiser
        </button>
    </form>
</div>

<div class="alert alert-light border d-flex align-items-start p-5 mb-6">
    <i class="bi bi-shield-check fs-2 text-primary me-3"></i>
    <div class="text-gray-700">
        <div class="fw-bold text-gray-800 mb-1">Utilisation des quotas</div>
        <div>
            Les lancements consomment le quota disponible au moment de l'exécution. Une sur-réservation indique que les réservations dépassent la capacité disponible: le premier lancement servi prend le quota restant, les suivants attendent.
        </div>
    </div>
</div>

<div class="row g-5 g-xl-8">

    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div>
                        <h3 class="card-label fw-bold fs-4 mb-0">Quota découverte</h3>
                        <div class="text-muted fs-7 mt-1">Requêtes de découverte</div>
                    </div>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">
                @if($serpapi === null)
                    <div class="alert alert-secondary text-muted mb-0">
                        Non disponible — mode local, clé API non configurée, ou panne fournisseur temporaire. Vérifiez la configuration ou réessayez dans quelques minutes.
                    </div>
                    <div class="text-muted fs-7 mt-3">Réservé aujourd’hui : {{ (int) ($providerReservations['serpapi_searches'] ?? 0) }}</div>
                @else
                    @php
                        $serpTotal = $serpapi['total_searches_left'] ?? null;
                        $serpCap = $serpapi['searches_per_month'] ?? null;
                        $serpUsed = ($serpTotal !== null && $serpCap !== null) ? max(0, (int) $serpCap - (int) $serpTotal) : null;
                        $serpReserved = (int) ($providerReservations['serpapi_searches'] ?? 0);
                        $serpAvailable = $serpTotal === null ? null : max(0, (int) $serpTotal);
                        $serpRemaining = $serpAvailable === null ? null : max(0, $serpAvailable - (int) $serpReserved);
                        $serpOverbooked = $serpAvailable === null ? 0 : max(0, (int) $serpReserved - $serpAvailable);
                    @endphp

                    <div class="fw-bold fs-2x text-gray-800 mb-2">{{ $serpRemaining ?? '—' }}</div>
                    <div class="text-muted fs-7 mb-5">requêtes restantes</div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Utilisé / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($serpUsed, $serpCap) }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Réservé / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($serpReserved, $serpCap) }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Restant / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($serpRemaining, $serpCap) }}</span>
                    </div>
                    @if($serpOverbooked > 0)
                        <div class="text-danger fs-7 mb-2">Surquota {{ $serpOverbooked }} au-delà du disponible</div>
                    @endif
                    @if($serpUsed !== null && $serpCap !== null && $serpCap > 0)
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($serpUsed + $serpReserved, $serpCap) }}" style="width: {{ $pct($serpUsed + $serpReserved, $serpCap) }}%"></div>
                        </div>
                    @else
                        <div class="mb-6"></div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Restant plan</span>
                        <span class="fw-bold text-gray-800">{{ $serpapi['plan_searches_left'] ?? '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Utilisé ce mois</span>
                        <span class="fw-bold text-gray-800">{{ $serpapi['this_month_usage'] ?? '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Plan</span>
                        <span class="fw-bold text-gray-800">{{ $serpapi['plan_name'] ?? '—' }}</span>
                    </div>
                    @if($serpapi['fetched_at'] ?? null)
                        <div class="text-muted fs-8 mt-4">Données du {{ \Illuminate\Support\Carbon::parse($serpapi['fetched_at'])->format('d/m/Y H:i') }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div>
                        <h3 class="card-label fw-bold fs-4 mb-0">Quota contacts</h3>
                        <div class="text-muted fs-7 mt-1">Recherches de contacts, distinctes des contacts créés</div>
                    </div>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">
                @if($hunter === null)
                    <div class="alert alert-secondary text-muted mb-0">
                        Non disponible — mode local, clé API non configurée, ou panne fournisseur temporaire. Vérifiez la configuration ou réessayez dans quelques minutes.
                    </div>
                    <div class="text-muted fs-7 mt-3">Réservé aujourd’hui : {{ (int) ($providerReservations['hunter_searches'] ?? 0) }}</div>
                @else
                    @php
                        $hunterSearchesUsed = $hunter['searches_used'] ?? null;
                        $hunterSearchesAvailableRaw = $hunter['searches_available'] ?? null;
                        // ponytail: the vendor can report a negative availability when the plan is overrun.
                        // Totals keep the raw value (used + available = plan size); arithmetic uses the
                        // clamped value, or an overrun would render as phantom over-reservation.
                        $hunterSearchesAvailable = $hunterSearchesAvailableRaw === null ? null : max(0, (int) $hunterSearchesAvailableRaw);
                        $hunterSearchesReserved = (int) ($providerReservations['hunter_searches'] ?? 0);
                        $hunterSearchesTotal = ($hunterSearchesUsed === null || $hunterSearchesAvailableRaw === null) ? null : max(0, (int) $hunterSearchesUsed + (int) $hunterSearchesAvailableRaw);
                        $hunterSearchesRemaining = $hunterSearchesAvailable === null ? null : max(0, $hunterSearchesAvailable - $hunterSearchesReserved);
                        $hunterSearchesOverbooked = $hunterSearchesAvailable === null ? 0 : max(0, $hunterSearchesReserved - $hunterSearchesAvailable);
                        $hunterVerificationsUsed = $hunter['verifications_used'] ?? null;
                        $hunterVerificationsAvailable = $hunter['verifications_available'] ?? null;
                        $hunterVerificationsTotal = ($hunterVerificationsUsed === null || $hunterVerificationsAvailable === null) ? null : max(0, (int) $hunterVerificationsUsed + (int) $hunterVerificationsAvailable);
                    @endphp

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700"><i class="bi bi-search me-1"></i> Recherches de contacts - Utilisé / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($hunterSearchesUsed, $hunterSearchesTotal) }}</span>
                    </div>
                    <div class="text-muted fs-7 mb-1">Disponible / Total : {{ $quotaValue($hunterSearchesAvailableRaw, $hunterSearchesTotal) }}</div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Réservé / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($hunterSearchesReserved, $hunterSearchesTotal) }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Restant / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($hunterSearchesRemaining, $hunterSearchesTotal) }}</span>
                    </div>
                    @if($hunterSearchesOverbooked > 0)
                        <div class="text-danger fs-7 mb-2">Surquota {{ $hunterSearchesOverbooked }} au-delà du disponible</div>
                    @endif
                    @if($hunterSearchesUsed !== null && $hunterSearchesTotal !== null)
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($hunterSearchesUsed + $hunterSearchesReserved, $hunterSearchesTotal) }}" style="width: {{ $pct($hunterSearchesUsed + $hunterSearchesReserved, $hunterSearchesTotal) }}%"></div>
                        </div>
                    @else
                        <div class="mb-6"></div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700"><i class="bi bi-check2-circle me-1"></i> Vérifications - Utilisé / Total</span>
                        <span class="fw-bold text-gray-800">{{ $quotaValue($hunterVerificationsUsed, $hunterVerificationsTotal) }}</span>
                    </div>
                    @if($hunterVerificationsUsed !== null && $hunterVerificationsTotal !== null)
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($hunterVerificationsUsed, $hunterVerificationsTotal) }}" style="width: {{ $pct($hunterVerificationsUsed, $hunterVerificationsTotal) }}%"></div>
                        </div>
                    @else
                        <div class="mb-6"></div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Plan</span>
                        <span class="fw-bold text-gray-800">{{ $hunter['plan_name'] ?? '—' }}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-gray-700">Réinitialisation</span>
                        <span class="fw-bold text-gray-800">{{ rescue(fn () => $hunter['reset_date'] ? \Illuminate\Support\Carbon::parse($hunter['reset_date'])->format('d/m/Y') : null, null, false) ?? '—' }}</span>
                    </div>
                    @if($hunter['fetched_at'] ?? null)
                        <div class="text-muted fs-8 mt-4">Données du {{ \Illuminate\Support\Carbon::parse($hunter['fetched_at'])->format('d/m/Y H:i') }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

</div>

<div class="card mt-5 mt-xl-8">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-4 mb-0">Consommation par opération</h3>
        </div>
    </div>
    <div class="card-body pt-3">
        @foreach($consumption as $block)
            <div class="mb-10">
                <h4 class="fw-bold fs-5 mb-4">{{ $block['label'] }}</h4>

                @if(! $block['has_calls'])
                    <div class="alert alert-secondary text-muted mb-0">Aucun appel enregistré sur la période.</div>
                @else
                    <div class="row g-5 mb-5">
                        <div class="col-6 col-xl-3">
                            <x-crud.stat-card icon="bi-graph-up-arrow" color="primary" label="Unités (7 j)" :value="$block['units_7d']" />
                        </div>
                        <div class="col-6 col-xl-3">
                            <x-crud.stat-card icon="bi-bar-chart" color="primary" label="Unités (30 j)" :value="$block['units_30d']" />
                        </div>
                        <div class="col-6 col-xl-3">
                            <x-crud.stat-card icon="bi-exclamation-triangle" color="danger" label="Échecs (30 j)" :value="$block['failed_30d']" :hint="'En vol : '.$block['in_flight_30d']" />
                        </div>
                        <div class="col-6 col-xl-3">
                            <x-crud.stat-card icon="bi-calendar-x" color="warning" label="Épuisement estimé" :value="$block['exhaustion']" />
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-5">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th>Opération</th>
                                    <th>Appels</th>
                                    <th>Réussis</th>
                                    <th>En vol</th>
                                    <th>Échecs</th>
                                    <th>Unités (30 j)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($block['operations'] as $operation)
                                    <tr>
                                        <td>{{ $operation['label'] }}</td>
                                        <td>{{ $operation['calls'] }}</td>
                                        <td>{{ $operation['succeeded'] }}</td>
                                        <td class="{{ $operation['in_flight'] > 0 ? 'text-warning fw-bold' : '' }}">{{ $operation['in_flight'] }}</td>
                                        <td class="{{ $operation['failed'] > 0 ? 'text-danger fw-bold' : '' }}">{{ $operation['failed'] }}</td>
                                        <td>{{ $operation['units_30d'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>

</x-default-layout>
