<x-default-layout>

@section('title')
    Ma consommation
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Ma consommation']]" />
@endsection

@php
    // Local bar styling for this page — deliberately NOT the severity rule in
    // DiscoveryQuotaService::displayMeters(). That rule is remaining-based, 3-valued, and
    // collapses daily+monthly into a single severity via max(); this page renders the two
    // periods as four independent bars and needs a 4th class besides (bg-primary =
    // healthy-limited vs bg-success = unlimited).
    // Never divides unless $cap > 0 — a cap of 0 is valid and means danger.
    $barClass = function (?int $used, ?int $cap): string {
        if ($cap === null) return 'bg-success';
        if ($cap <= 0)     return 'bg-danger';
        $ratio = $used / $cap;
        if ($ratio >= 1)   return 'bg-danger';
        if ($ratio >= 0.8) return 'bg-warning';
        return 'bg-primary';
    };
    $pct = function (?int $used, ?int $cap): int {
        if ($cap === null || $cap <= 0) return 0;
        return (int) min(100, round(($used / $cap) * 100));
    };

    $dailyCompanySummary = $dailyQuotaSummary['company'] ?? [];
    $dailyContactSummary = $dailyQuotaSummary['contacts'] ?? [];
    $monthlyCompanySummary = $monthlyQuotaSummary['company'] ?? [];
    $monthlyContactSummary = $monthlyQuotaSummary['contacts'] ?? [];
@endphp

{{-- ── Card : Pack actif ────────────────────────────────────────────────── --}}
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
        @if($package)
            <div class="d-flex align-items-center">
                <div class="symbol symbol-circle symbol-50px bg-light-primary me-4">
                    <span class="symbol-label fs-2 text-primary fw-bold">
                        {{ mb_strtoupper(mb_substr($package->name, 0, 1)) }}
                    </span>
                </div>
                <div>
                    <span class="fw-bold fs-4 text-gray-800">{{ $package->name }}</span>
                    <div class="text-muted fs-7 mt-1">
                        Période en cours : du {{ $periodStart->format('d/m/Y') }} au {{ $periodEnd->copy()->subDay()->format('d/m/Y') }}
                    </div>
                </div>
            </div>
        @else
            <div class="text-muted">
                <i class="bi bi-infinity me-1"></i>
                Aucun pack assigné — accès en mode <strong>illimité</strong>.
            </div>
        @endif
    </div>
</div>

{{-- ── Cards : Aujourd'hui / Ce mois ───────────────────────────────────── --}}
<div class="row g-5 g-xl-8 mb-6">

    {{-- Aujourd'hui --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="card-label fw-bold fs-4 mb-0">Aujourd'hui</h3>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">

                {{-- Découvertes --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700"><i class="bi bi-building me-1"></i> Découvertes</span>
                    @if($isUnlimited)
                        <span class="badge badge-light-success">Illimité</span>
                    @else
                        <span class="fw-bold text-gray-800">Utilisé + réservé : {{ $dailyCompanySummary['used_reserved'] ?? $usedToday }} / {{ $dailyCap }}</span>
                    @endif
                </div>
                @unless($isUnlimited)
                    <div class="text-muted fs-8 mb-2 text-end">Restant : {{ $dailyCompanySummary['remaining'] ?? max(0, $dailyCap - $usedToday) }}</div>
                @endunless
                @unless($isUnlimited)
                    <div class="progress h-8px mb-6">
                        <div class="progress-bar {{ $barClass($usedToday, $dailyCap) }}" style="width: {{ $pct($usedToday, $dailyCap) }}%"></div>
                    </div>
                @else
                    <div class="mb-6"></div>
                @endunless

                {{-- Contacts (enrichissement) --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700"><i class="bi bi-person-lines-fill me-1"></i> Contacts (enrichissement)</span>
                    @if($contactIsUnlimited)
                        <span class="badge badge-light-success">Illimité</span>
                    @else
                        <span class="fw-bold text-gray-800">Utilisé + réservé : {{ $dailyContactSummary['used_reserved'] ?? $contactUsedToday }} / {{ $dailyContactCap }}</span>
                    @endif
                </div>
                @unless($contactIsUnlimited)
                    <div class="text-muted fs-8 mb-2 text-end">Restant : {{ $dailyContactSummary['remaining'] ?? max(0, $dailyContactCap - $contactUsedToday) }}</div>
                @endunless
                @unless($contactIsUnlimited)
                    <div class="progress h-8px">
                        <div class="progress-bar {{ $barClass($contactUsedToday, $dailyContactCap) }}" style="width: {{ $pct($contactUsedToday, $dailyContactCap) }}%"></div>
                    </div>
                @endunless

            </div>
        </div>
    </div>

    {{-- Ce mois --}}
    <div class="col-xl-6">
        <div class="card card-xl-stretch mb-5 mb-xl-8">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="card-label fw-bold fs-4 mb-0">Ce mois</h3>
                </div>
            </div>
            <div class="card-body pt-3 pb-6">

                {{-- Découvertes --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700"><i class="bi bi-building me-1"></i> Découvertes</span>
                    @if($monthlyIsUnlimited)
                        <span class="badge badge-light-success">Illimité</span>
                    @else
                        <span class="fw-bold text-gray-800">Utilisé + réservé : {{ $monthlyCompanySummary['used_reserved'] ?? $usedThisMonth }} / {{ $monthlyCap }}</span>
                    @endif
                </div>
                @unless($monthlyIsUnlimited)
                    <div class="text-muted fs-8 mb-2 text-end">Restant : {{ $monthlyCompanySummary['remaining'] ?? max(0, $monthlyCap - $usedThisMonth) }}</div>
                @endunless
                @unless($monthlyIsUnlimited)
                    <div class="progress h-8px mb-6">
                        <div class="progress-bar {{ $barClass($usedThisMonth, $monthlyCap) }}" style="width: {{ $pct($usedThisMonth, $monthlyCap) }}%"></div>
                    </div>
                @else
                    <div class="mb-6"></div>
                @endunless

                {{-- Contacts (enrichissement) --}}
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold text-gray-700"><i class="bi bi-person-lines-fill me-1"></i> Contacts (enrichissement)</span>
                    @if($monthlyContactIsUnlimited)
                        <span class="badge badge-light-success">Illimité</span>
                    @else
                        <span class="fw-bold text-gray-800">Utilisé + réservé : {{ $monthlyContactSummary['used_reserved'] ?? $contactUsedThisMonth }} / {{ $monthlyContactCap }}</span>
                    @endif
                </div>
                @unless($monthlyContactIsUnlimited)
                    <div class="text-muted fs-8 mb-2 text-end">Restant : {{ $monthlyContactSummary['remaining'] ?? max(0, $monthlyContactCap - $contactUsedThisMonth) }}</div>
                @endunless
                @unless($monthlyContactIsUnlimited)
                    <div class="progress h-8px">
                        <div class="progress-bar {{ $barClass($contactUsedThisMonth, $monthlyContactCap) }}" style="width: {{ $pct($contactUsedThisMonth, $monthlyContactCap) }}%"></div>
                    </div>
                @endunless

            </div>
        </div>
    </div>

</div>

{{-- ── Card : Consommation (30 jours) ──────────────────────────────────── --}}
<div class="card mb-6">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-4 mb-0">
                <i class="bi bi-bar-chart text-info fs-3 me-2"></i>
                Consommation (30 jours)
            </h3>
        </div>
    </div>
    <div class="card-body py-3">
        @if(array_sum($series['discoveries']) > 0 || array_sum($series['contacts']) > 0)
            <div id="kt_consumption_chart" style="min-height: 280px;"></div>
        @else
            <div class="text-center text-muted py-10">
                <i class="bi bi-bar-chart fs-3x text-muted mb-3 d-block"></i>
                Aucune activité sur les 30 derniers jours.
            </div>
        @endif
    </div>
</div>

{{-- ── Card : Détail par critère (période en cours) ────────────────────── --}}
<div class="card">
    <div class="card-header border-0 pt-6">
        <div class="card-title">
            <h3 class="card-label fw-bold fs-4 mb-0">Détail par critère (période en cours)</h3>
        </div>
    </div>
    <div class="card-body py-3">
        @if($breakdown->isEmpty())
            <div class="text-center py-6 text-muted">
                <i class="bi bi-table fs-2x mb-2 d-block"></i>
                Aucune activité sur la période en cours.
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-row-dashed table-row-gray-200 align-middle gs-0 gy-3 fs-6">
                    <thead>
                        <tr class="fw-bold text-muted">
                            <th>Critère</th>
                            <th class="text-center">Runs</th>
                            <th class="text-center">Entreprises</th>
                            <th class="text-center">Nouvelles</th>
                            <th class="text-center">Contacts</th>
                            <th class="text-center">Découvertes</th>
                            <th class="text-center">Contacts (crédits)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($breakdown as $row)
                            <tr>
                                <td class="fw-semibold">{{ $row->label }}</td>
                                <td class="text-center">{{ (int) $row->runs_count }}</td>
                                <td class="text-center">{{ (int) $row->companies_count }}</td>
                                <td class="text-center">{{ (int) $row->new_companies_count }}</td>
                                <td class="text-center">{{ (int) $row->contacts_count }}</td>
                                <td class="text-center">{{ (int) $row->consumed }}</td>
                                <td class="text-center">{{ (int) $row->contact_consumed }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
"use strict";

(function () {
    var el = document.getElementById('kt_consumption_chart');
    if (el && typeof ApexCharts !== 'undefined') {
        var series = @json($series);

        var options = {
            series: [
                { name: 'Découvertes', data: series.discoveries },
                { name: 'Contacts',    data: series.contacts }
            ],
            chart: {
                type: 'line',
                height: 280,
                toolbar: { show: false },
                zoom: { enabled: false }
            },
            colors: ['#009EF7', '#50CD89'],
            stroke: { curve: 'smooth', width: 2 },
            markers: { size: 3 },
            xaxis: {
                categories: series.dates,
                labels: { rotate: -30, style: { fontSize: '10px' } }
            },
            yaxis: {
                labels: { formatter: function (val) { return Math.round(val); } }
            },
            legend: { position: 'top' },
            grid: { borderColor: '#F4F4F4' },
            tooltip: {
                y: { formatter: function (val) { return val; } }
            }
        };

        var chart = new ApexCharts(el, options);
        chart.render();
    }
}());
</script>
@endpush

</x-default-layout>
