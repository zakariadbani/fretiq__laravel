<x-default-layout>

@section('title')
    Quota fournisseurs
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Quota fournisseurs</li>
    </ul>
@endsection

@php
    // Severity helper — mirrors the idiom in prospect_criteria/partials/_quota-badge.blade.php
    // (never divides unless $cap > 0 — a cap of 0 is valid and means danger).
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
@endphp

@if($driverLive === false)
    <div class="alert alert-info d-flex align-items-center p-5 mb-6">
        <i class="bi bi-info-circle fs-2 text-info me-3"></i>
        <div class="text-gray-700">Mode local actif — données fournisseurs indisponibles.</div>
    </div>
@endif

<div class="row g-5 g-xl-8">

    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div>
                        <h3 class="card-label fw-bold fs-4 mb-0">SerpAPI</h3>
                        <div class="text-muted fs-7 mt-1">Découverte</div>
                    </div>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">
                @if($serpapi === null)
                    <div class="alert alert-secondary text-muted mb-0">
                        Non disponible — mode local ou clé API non configurée. Renseignez les clés et passez DISCOVERY_DRIVER=live.
                    </div>
                @else
                    @php
                        $serpTotal = $serpapi['total_searches_left'] ?? null;
                        $serpCap = $serpapi['searches_per_month'] ?? null;
                        $serpUsed = ($serpTotal !== null && $serpCap !== null) ? max(0, $serpCap - $serpTotal) : null;
                    @endphp

                    <div class="fw-bold fs-2x text-gray-800 mb-2">{{ $serpTotal ?? '—' }}</div>
                    <div class="text-muted fs-7 mb-5">recherches restantes</div>

                    @if($serpUsed !== null && $serpCap !== null && $serpCap > 0)
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold text-gray-700">Utilisation mensuelle</span>
                            <span class="fw-bold text-gray-800">{{ $serpUsed }} / {{ $serpCap }}</span>
                        </div>
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($serpUsed, $serpCap) }}" style="width: {{ $pct($serpUsed, $serpCap) }}%"></div>
                        </div>
                    @else
                        <div class="d-flex justify-content-between align-items-center mb-6">
                            <span class="fw-semibold text-gray-700">Utilisation mensuelle</span>
                            <span class="fw-bold text-gray-800">—</span>
                        </div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700">Quota du plan restant</span>
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
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-gray-700">Compte</span>
                        <span class="fw-bold text-gray-800">{{ $serpapi['account_email'] ?? '—' }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <div>
                        <h3 class="card-label fw-bold fs-4 mb-0">Hunter</h3>
                        <div class="text-muted fs-7 mt-1">Enrichissement</div>
                    </div>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">
                @if($hunter === null)
                    <div class="alert alert-secondary text-muted mb-0">
                        Non disponible — mode local ou clé API non configurée. Renseignez les clés et passez DISCOVERY_DRIVER=live.
                    </div>
                @else
                    @php
                        $hunterSearchesUsed = $hunter['searches_used'] ?? null;
                        $hunterSearchesAvailable = $hunter['searches_available'] ?? null;
                        $hunterVerificationsUsed = $hunter['verifications_used'] ?? null;
                        $hunterVerificationsAvailable = $hunter['verifications_available'] ?? null;
                    @endphp

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700"><i class="bi bi-search me-1"></i> Recherches</span>
                        @if($hunterSearchesUsed !== null && $hunterSearchesAvailable !== null)
                            <span class="fw-bold text-gray-800">{{ $hunterSearchesUsed }} / {{ $hunterSearchesAvailable }}</span>
                        @else
                            <span class="fw-bold text-gray-800">—</span>
                        @endif
                    </div>
                    @if($hunterSearchesUsed !== null && $hunterSearchesAvailable !== null)
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($hunterSearchesUsed, $hunterSearchesAvailable) }}" style="width: {{ $pct($hunterSearchesUsed, $hunterSearchesAvailable) }}%"></div>
                        </div>
                    @else
                        <div class="mb-6"></div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold text-gray-700"><i class="bi bi-check2-circle me-1"></i> Vérifications</span>
                        @if($hunterVerificationsUsed !== null && $hunterVerificationsAvailable !== null)
                            <span class="fw-bold text-gray-800">{{ $hunterVerificationsUsed }} / {{ $hunterVerificationsAvailable }}</span>
                        @else
                            <span class="fw-bold text-gray-800">—</span>
                        @endif
                    </div>
                    @if($hunterVerificationsUsed !== null && $hunterVerificationsAvailable !== null)
                        <div class="progress h-8px mb-6">
                            <div class="progress-bar {{ $barClass($hunterVerificationsUsed, $hunterVerificationsAvailable) }}" style="width: {{ $pct($hunterVerificationsUsed, $hunterVerificationsAvailable) }}%"></div>
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
                        <span class="fw-bold text-gray-800">{{ $hunter['reset_date'] ?? '—' }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>

</x-default-layout>