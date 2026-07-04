<x-default-layout>

@section('title')
    Packs
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">Packs</li>
    </ul>
@endsection

{{-- ── Card 1 : Pack actif ─────────────────────────────────────────────── --}}
@can('manage packages')
<div class="card mb-6">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-3 mb-0">
                <i class="bi bi-box-seam text-primary fs-3 me-2"></i>
                Pack actif
            </h3>
        </div>
    </div>
    <div class="card-body pt-3 pb-6">

        @if($activeAssignment && $activeAssignment->package)
            @php
                $pkg            = $activeAssignment->package;
                $credits        = $pkg->daily_credits;
                $contactCredits = $pkg->daily_contact_credits;
                $mCredits       = $pkg->monthly_credits;
                $mContactCredits= $pkg->monthly_contact_credits;
                $since          = $activeAssignment->created_at ? $activeAssignment->created_at->format('d/m/Y') : '—';
                $by             = optional($activeAssignment->assignedBy)->name ?? 'Système';
                // Indicative guardrail: daily × 30 (shown only when no real monthly cap)
                $maxCallsMonth  = ($credits !== null && $mCredits === null) ? ($credits * 30) : null;
            @endphp

            <div class="d-flex align-items-center mb-4">
                <div class="symbol symbol-circle symbol-50px bg-light-primary me-4">
                    <span class="symbol-label fs-2 text-primary fw-bold">
                        {{ mb_strtoupper(mb_substr($pkg->name, 0, 1)) }}
                    </span>
                </div>
                <div>
                    <span class="fw-bold fs-4 text-gray-800 me-2">{{ $pkg->name }}</span>
                    <div class="d-flex flex-wrap gap-2 mt-1">

                        {{-- Company caps --}}
                        <span class="badge @if($credits === null) badge-light-success @else badge-light-primary @endif">
                            <i class="bi bi-building me-1"></i>
                            Découvertes : {{ $credits === null ? 'Illimité' : $credits . ' /j' }}
                        </span>
                        @if($mCredits !== null)
                            <span class="badge badge-light-primary">
                                <i class="bi bi-calendar3 me-1"></i>
                                {{ $mCredits }} / mois
                                @if(($monthlyRemainingToday ?? null) !== null)
                                    &nbsp;&middot;&nbsp;<strong>{{ $monthlyRemainingToday }}</strong> restants ce mois
                                @endif
                            </span>
                        @endif

                        {{-- Contact caps --}}
                        <span class="badge @if($contactCredits === null) badge-light-success @else badge-light-info @endif">
                            <i class="bi bi-person-lines-fill me-1"></i>
                            Contacts : {{ $contactCredits === null ? 'Illimité' : $contactCredits . ' /j' }}
                        </span>
                        @if($mContactCredits !== null)
                            <span class="badge badge-light-info">
                                <i class="bi bi-calendar3 me-1"></i>
                                {{ $mContactCredits }} / mois
                                @if(($monthlyContactRemainingToday ?? null) !== null)
                                    &nbsp;&middot;&nbsp;<strong>{{ $monthlyContactRemainingToday }}</strong> restants ce mois
                                @endif
                            </span>
                        @endif

                    </div>
                    <div class="text-muted fs-7 mt-1">
                        Assigné le {{ $since }} par <strong>{{ $by }}</strong>
                    </div>
                    @if($maxCallsMonth !== null)
                        <div class="text-muted fs-7 mt-1">
                            <i class="bi bi-info-circle me-1"></i>
                            Indicatif&nbsp;: {{ $credits }} × 30 = <strong>{{ $maxCallsMonth }}</strong> crédits de découverte max / mois (aucune limite mensuelle configurée)
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="text-muted mb-4">
                <i class="bi bi-exclamation-circle me-1"></i>
                Aucun pack assigné — la découverte est en mode <strong>illimité</strong> par convention.
            </div>
        @endif

        {{-- Assign form --}}
        <form method="POST" action="{{ route('admin.packages.assign') }}" class="d-flex align-items-center gap-3 flex-wrap">
            @csrf
            <select name="package_id" class="form-select form-select-solid w-auto" required>
                <option value="">Sélectionner un pack…</option>
                @foreach($activePackages as $pkg)
                    <option value="{{ $pkg->id }}"
                        {{ ($activeAssignment && $activeAssignment->package_id === $pkg->id) ? 'selected' : '' }}
                        data-credits="{{ $pkg->daily_credits ?? 'null' }}">
                        {{ $pkg->name }}
                        {{ $pkg->daily_credits === null ? '(Ent: ∞' : '(Ent: ' . $pkg->daily_credits }}{{ $pkg->daily_contact_credits === null ? ' / Cont: ∞)' : ' / Cont: ' . $pkg->daily_contact_credits . ')' }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-check2 me-1"></i>
                Assigner
            </button>
            <span id="guardrail-text" class="text-muted fs-7"></span>
        </form>

    </div>
</div>
@endcan

{{-- ── Card : Capacité fournisseur (mois en cours) ─────────────────────── --}}
@can('manage packages')
@if($capacity)
<div class="card mb-6">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-3 mb-0">
                <i class="bi bi-speedometer2 text-warning fs-3 me-2"></i>
                Capacité fournisseur (mois en cours)
            </h3>
        </div>
    </div>
    <div class="card-body pt-3 pb-6">

        @if($capacity['enrich']['capacity'] !== null)
            @php
                $enrichCap      = $capacity['enrich']['capacity'];
                $enrichSold     = $capacity['enrich']['sold'];
                $enrichConsumed = $capacity['enrich']['consumed'];
                $enrichPct      = $enrichCap > 0 ? min(100, round(($enrichConsumed / $enrichCap) * 100)) : 0;
                $enrichBar      = $enrichPct >= 100 ? 'bg-danger' : ($enrichPct >= 80 ? 'bg-warning' : 'bg-success');
            @endphp
            <div class="mb-6">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700">
                        <i class="bi bi-person-lines-fill me-1"></i> Enrichissement (contacts)
                    </span>
                    <span class="text-muted fs-7">Capacité : <strong>{{ number_format($enrichCap) }}</strong></span>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge badge-light-primary">
                        Vendu au client actif :
                        @if($enrichSold === null)
                            Illimité
                            @if($enrichCap !== null)
                                <i class="bi bi-exclamation-triangle text-warning ms-1" title="Pack illimité mais capacité fournisseur finie"></i>
                            @endif
                        @else
                            {{ number_format($enrichSold) }} / mois
                        @endif
                    </span>
                    <span class="badge badge-light-info">Consommé : {{ number_format($enrichConsumed) }}</span>
                </div>
                <div class="progress h-8px">
                    <div class="progress-bar {{ $enrichBar }}" style="width: {{ $enrichPct }}%"></div>
                </div>
            </div>
        @endif

        @if($capacity['discovery']['capacity'] !== null)
            @php
                $discCap       = $capacity['discovery']['capacity'];
                $discEstimated = $capacity['discovery']['estimated_searches'];
                $discPct       = $discCap > 0 ? min(100, round(($discEstimated / $discCap) * 100)) : 0;
                $discBar       = $discPct >= 100 ? 'bg-danger' : ($discPct >= 80 ? 'bg-warning' : 'bg-success');
            @endphp
            <div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700">
                        <i class="bi bi-building me-1"></i> Découverte (recherches)
                    </span>
                    <span class="text-muted fs-7">Capacité : <strong>{{ number_format($discCap) }}</strong></span>
                </div>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge badge-light-warning">
                        Recherches estimées : ~{{ number_format($discEstimated) }} (estimation haute)
                    </span>
                </div>
                <div class="progress h-8px">
                    <div class="progress-bar {{ $discBar }}" style="width: {{ $discPct }}%"></div>
                </div>
                <div class="text-muted fs-7 mt-1">
                    Estimation : nombre de runs × requêtes max/run — les recherches individuelles ne sont pas persistées.
                </div>
            </div>
        @endif

    </div>
</div>
@endif
@endcan

{{-- ── Card 2 : Consommation (14 derniers jours) ──────────────────────── --}}
@can('manage packages')
<div class="card mb-6">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-3 mb-0">
                <i class="bi bi-bar-chart text-info fs-3 me-2"></i>
                Consommation (14 derniers jours)
            </h3>
        </div>
        <div class="card-toolbar">
            <span class="text-muted fs-7">
                Aujourd'hui —
                Entreprises :
                @if($isUnlimited)
                    <span class="badge badge-light-success">Illimité</span>
                @else
                    <strong>{{ $remainingToday }}</strong> restants
                @endif
                &nbsp;·&nbsp;
                Contacts :
                @if(($contactRemainingToday ?? null) === null)
                    <span class="badge badge-light-success">Illimité</span>
                @else
                    <strong>{{ $contactRemainingToday }}</strong> restants
                @endif
            </span>
        </div>
    </div>
    <div class="card-body pt-3 pb-6">
        @if($ledger->isEmpty())
            <div class="text-center py-6 text-muted">
                <i class="bi bi-bar-chart fs-2x mb-2 d-block"></i>
                Aucune activité sur les 14 derniers jours.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-200 align-middle gs-0 gy-3 fs-6">
                    <thead>
                        <tr class="fw-bold text-muted">
                            <th>Date</th>
                            <th>Runs</th>
                            <th>Réservés</th>
                            <th>Entreprises</th>
                            <th>Contacts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ledger as $row)
                            <tr>
                                <td class="fw-semibold">{{ \Carbon\Carbon::parse($row->quota_date)->format('d/m/Y') }}</td>
                                <td>
                                    <span class="badge badge-light-primary">{{ $row->runs_count }}</span>
                                </td>
                                <td>{{ (int) $row->total_reserved }}</td>
                                <td>
                                    @if((int) $row->total_consumed > 0)
                                        <span class="text-success fw-semibold">{{ (int) $row->total_consumed }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    @if((int) ($row->total_contact_consumed ?? 0) > 0)
                                        <span class="text-info fw-semibold">{{ (int) $row->total_contact_consumed }}</span>
                                    @else
                                        <span class="text-muted">0</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endcan

{{-- ── DataTable card ──────────────────────────────────────────────────── --}}
<div class="card">
    {{-- Card header --}}
    <div class="card-header border-0 pt-6">
        {{-- Search --}}
        <div class="card-title">
            <div class="d-flex align-items-center position-relative my-1">
                {!! getIcon('magnifier', 'fs-3 position-absolute ms-5') !!}
                <input type="text"
                       data-kt-table-filter="search"
                       class="form-control form-control-solid w-250px ps-13"
                       placeholder="Rechercher un pack"
                       id="mySearchInput" />
            </div>
        </div>

        {{-- Toolbar --}}
        <div class="card-toolbar">
            <div class="d-flex justify-content-end" data-kt-table-toolbar="base">

                {{-- Filter button --}}
                <button type="button"
                        class="btn btn-light-primary me-3"
                        data-kt-menu-trigger="click"
                        data-kt-menu-placement="bottom-end">
                    {!! getIcon('filter', 'fs-2', '', 'i') !!}
                    Filtrer
                </button>

                {{-- Filter dropdown --}}
                <div class="menu menu-sub menu-sub-dropdown w-300px w-md-325px" data-kt-menu="true">
                    <div class="px-7 py-5">
                        <div class="fs-5 text-gray-900 fw-bold">Options de filtrage</div>
                    </div>
                    <div class="separator border-gray-200"></div>
                    <div class="px-7 py-5" data-kt-table-filter="form">
                        <div id="filters-container"></div>
                        <div class="d-flex justify-content-end">
                            <button type="reset"
                                    class="btn btn-light btn-active-light-primary fw-semibold me-2 px-6"
                                    data-kt-menu-dismiss="true"
                                    data-kt-table-filter="reset">
                                Réinitialiser
                            </button>
                            <button type="submit"
                                    class="btn btn-primary fw-semibold px-6"
                                    data-kt-menu-dismiss="true"
                                    data-kt-table-filter="filter">
                                Appliquer
                            </button>
                        </div>
                    </div>
                </div>

                @can('manage packages')
                <a href="{{ route('admin.packages.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-lg fs-2"></i>
                    Ajouter un Pack
                </a>
                @endcan
            </div>
        </div>
    </div>

    {{-- Card body — datatable --}}
    <div class="card-body py-4">
        <div class="table-responsive">
            {{ $dataTable->table(['class' => 'table align-middle table-row-dashed fs-6 gy-5']) }}
        </div>
    </div>
</div>

@push('scripts')
    {{ $dataTable->scripts() }}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            DataTableUtils.initializeIndex(@json($dataTableConfig));

            // Cost guardrail: update text when a different package is selected
            var sel = document.querySelector('select[name="package_id"]');
            var guardrail = document.getElementById('guardrail-text');
            if (sel && guardrail) {
                function updateGuardrail() {
                    var opt = sel.options[sel.selectedIndex];
                    if (!opt || opt.value === '') {
                        guardrail.textContent = '';
                        return;
                    }
                    var credits = opt.getAttribute('data-credits');
                    if (credits === 'null' || credits === null) {
                        guardrail.textContent = 'Pack illimité — aucune limite de découverte.';
                    } else {
                        var max = parseInt(credits, 10) * 30;
                        guardrail.textContent = credits + ' × 30 = ' + max + ' crédits de découverte max / mois';
                    }
                }
                sel.addEventListener('change', updateGuardrail);
                updateGuardrail();
            }
        });
    </script>
@endpush

</x-default-layout>
