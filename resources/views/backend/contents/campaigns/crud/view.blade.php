<x-default-layout>

@section('title')
    Campagne — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Campagnes', 'route' => 'admin.campaigns.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.campaigns.index'])
@endsection

{{--
    Campaign view — hero+tabbar UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: campaign_apercu / campaign_historique / campaign_audience_actuelle / campaign_destinataires.
    campaign_general deep-links to the edit page (no native pane here).
    Aperçu is the default active pane (native on view page).

    Tab layout:
      1. Aperçu    — _apercu partial + sequence enrolled card + latest-run KPI cards
      2. Général   — cross-route link to edit page
      3. Historique — @include(_historique-tab)  full execution history table
      4. Audience actuelle — live segment audience (recomputed on render)
      5. Destinataires — @include(_destinataires-tab)  all recipients across all runs (paginated)

    Schedule / SendNow / Planifier buttons: preserved verbatim in _header-actions (hero slot).
    All @can('send campaigns') gates are kept exactly as the original.
    JS handlers (Swal + axios) are kept verbatim in @push('scripts').
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.campaigns.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

@if($model->isOverdue())
    <div class="alert alert-danger d-flex align-items-center mb-6">
        <i class="bi bi-exclamation-triangle-fill fs-4 me-3 text-danger"></i>
        <div>Cette campagne est en retard. Vérifiez sa planification et le planificateur.</div>
    </div>
@endif

@include('backend.contents.campaigns.partials._scheduler-health', ['schedulerHealth' => $schedulerHealth ?? []])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="campaign_apercu" role="tabpanel">

        {{-- Generic apercu: details table (left) + stat cards + charts (right) --}}
        <div class="text-muted fs-7 fw-semibold mb-2">
            Vue cumulee - duree de vie de la campagne, executions envoyees uniquement.
        </div>
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => $viewConfig ?? \App\Crud\ViewConfigs\CampaignViewConfig::make($model, $stats ?? null, $recipientsTotal ?? null),
        ])

        @if($model->schedule_type === 'paced' && is_array($pacedProgress ?? null))
        <div class="card card-flush mt-6">
            <div class="card-header pt-5">
                <h3 class="card-title fw-bold">Progression des sociétés</h3>
            </div>
            <div class="card-body pt-2">
                <div class="row g-4">
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-4 h-100" data-paced-processed="{{ $pacedProgress['processed'] }}">
                            <div class="fs-2 fw-bold text-success">{{ number_format($pacedProgress['processed']) }}</div>
                            <div class="text-muted fw-semibold">Sociétés traitées</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-4 h-100" data-paced-backlog-companies="{{ $pacedProgress['backlog_companies'] }}" data-paced-backlog-contacts="{{ $pacedProgress['backlog_contacts'] }}">
                            <div class="fs-2 fw-bold text-primary">{{ number_format($pacedProgress['backlog_companies']) }}</div>
                            <div class="text-muted fw-semibold">File actuelle</div>
                            <div class="text-muted fs-8">{{ number_format($pacedProgress['backlog_contacts']) }} contact(s)</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-4 h-100" data-paced-failed="{{ $pacedProgress['failed'] }}">
                            <div class="fs-2 fw-bold text-danger">{{ number_format($pacedProgress['failed']) }}</div>
                            <div class="text-muted fw-semibold">Sociétés en échec</div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-xl-3">
                        <div class="border rounded p-4 h-100" data-paced-last-batch-companies="{{ $pacedProgress['last_batch_companies'] }}">
                            <div class="fw-bold text-gray-900">
                                @if($pacedProgress['last_batch'])
                                    {{ number_format($pacedProgress['last_batch_companies']) }} société(s)
                                @else
                                    —
                                @endif
                            </div>
                            <div class="text-muted fw-semibold">Dernier lot</div>
                            @if($pacedProgress['last_batch'])
                                <div class="text-muted fs-8">{{ $pacedProgress['last_batch']->run_at->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y') }}</div>
                            @endif
                            <div class="text-muted fs-8 mt-2">
                                Prochain lot : {{ $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') : 'non défini' }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- ── Sequence stat: enrolled contacts ─────────────────────────── --}}
        @if($model->schedule_type === 'sequence')
        <div class="row g-4 mt-2">

            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($enrolledCount ?? 0) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Contacts inscrits</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        @if($model->sequence)
                            <a href="{{ route('admin.sequences.view', $model->sequence->id) }}" class="fs-7 text-primary">
                                <i class="bi bi-eye me-1"></i>
                                Voir le suivi des contacts
                            </a>
                        @else
                            <span class="text-muted fs-7">Aucune séquence associée</span>
                        @endif
                    </div>
                </div>
            </div>

            @if($model->sequence_enrollment_mode === 'paced')
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($enrolledCompanyCount ?? 0) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Sociétés inscrites</span>
                        </div>
                    </div>
                    <div class="card-body pt-0 text-muted fs-7">
                        Jusqu’à {{ $model->pacedDailyCompanyLimit() }} société(s) par jour ouvré.
                        <div class="mt-1">Prochain lot : {{ $model->next_run_at ? $model->next_run_at->copy()->setTimezone($model->scheduleTimezone())->format('d/m/Y H:i') : 'non défini' }}</div>
                    </div>
                </div>
            </div>
            @endif

        </div>
        @endif

        {{-- ── Preserved: KPIs from latest run ──────────────────────────── --}}
        @if($latestRun)
        @php($latestKpis = $latestRun->kpis())
        <div class="text-muted fs-7 fw-semibold mt-6 mb-2">
            Derniere execution envoyee - statistiques de ce run uniquement.
        </div>
        <div class="row g-4 mt-2">

            {{-- Envoyés --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestKpis['sent'] ?? 0) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Envoyés</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <div class="d-flex flex-column content-justify-center flex-row-fluid">
                            <i class="bi bi-send fs-2x text-primary opacity-75"></i>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Taux d'ouverture --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ \App\Models\CampaignRun::rateLabel($latestKpis['open_rate'] ?? null) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Taux d'ouverture</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">
                            @if(($latestKpis['denominator'] ?? null) !== null)
                                {{ number_format($latestKpis['opened'] ?? 0) }} ouverts / {{ number_format($latestKpis['denominator']) }} {{ ($latestKpis['delivered'] ?? 0) > 0 ? 'délivrés' : 'envoyés' }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                </div>
            </div>

            {{-- Taux de clic --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ \App\Models\CampaignRun::rateLabel($latestKpis['click_rate'] ?? null) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Taux de clic</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">{{ number_format($latestKpis['clicked'] ?? 0) }} clics</span>
                    </div>
                </div>
            </div>

            {{-- Réponses --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestKpis['replied'] ?? 0) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Réponses</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <i class="bi bi-reply fs-2x text-success opacity-75"></i>
                    </div>
                </div>
            </div>

            {{-- Conversion --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ \App\Models\CampaignRun::rateLabel($latestKpis['conversion_rate'] ?? null) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Conversion</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">{{ number_format($latestKpis['conversions'] ?? 0) }} conversion(s)</span>
                    </div>
                </div>
            </div>

            {{-- Rebonds --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestKpis['bounced'] ?? 0) }}</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Rebonds</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <i class="bi bi-x-circle fs-2x text-danger opacity-75"></i>
                    </div>
                </div>
            </div>

        </div>
        @else
            <div class="card mt-4 d-flex align-items-center justify-content-center">
                <div class="text-center py-10">
                    <i class="bi bi-bar-chart fs-2x text-muted mb-4 d-block"></i>
                    <div class="text-muted fw-semibold">Aucune exécution pour cette campagne.</div>
                    <div class="text-muted fs-7 mt-1">Planifiez ou envoyez la campagne pour voir les statistiques.</div>
                </div>
            </div>
        @endif

    </div>
    {{-- end campaign_apercu --}}

    {{-- ── Tab: Historique (exécutions) ─────────────────────────────────── --}}
    <div class="tab-pane fade" id="campaign_historique" role="tabpanel">
        @include('backend.contents.campaigns.partials._historique-tab')
    </div>

    @if($model->schedule_type === 'sequence' && $model->sequence_enrollment_mode === 'paced')
    <div class="tab-pane fade" id="campaign_vagues" role="tabpanel">
        @include('backend.contents.campaigns.partials._vagues-tab')
    </div>
    @endif
    {{-- ── Tab: Audience actuelle (segment recalculé) ───────────────────────── --}}
    <div class="tab-pane fade" id="campaign_audience_actuelle" role="tabpanel">
        @php($audienceActuelle = $currentAudience ?? collect())

        <div class="card">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-people text-primary fs-3 me-2"></i>
                    Audience actuelle ({{ number_format($audienceActuelle->count()) }})
                </h3>
            </div>

            <div class="card-body pt-0">
                <div class="alert bg-light-primary d-flex align-items-start p-4 rounded">
                    <i class="bi bi-info-circle text-primary fs-4 me-3 mt-1"></i>
                    <div class="text-gray-700 fw-semibold">
                        Cette audience est recalculée dynamiquement à partir du segment lié.
                        Les envois passés restent dans l'onglet Destinataires.
                    </div>
                </div>

                @if($audienceActuelle->isEmpty())
                    <div class="text-center py-8 text-muted">
                        <i class="bi bi-people fs-2x mb-3 d-block"></i>
                        Aucun contact éligible dans l'audience actuelle.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-row-dashed table-row-gray-300 align-middle gs-0 gy-4 mb-0">
                            <thead>
                                <tr class="fw-bold text-muted bg-light">
                                    <th class="ps-7">Email</th>
                                    <th>Société</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($audienceActuelle as $contact)
                                <tr>
                                    <td class="ps-7 fw-semibold">{{ $contact->email ?: '—' }}</td>
                                    <td class="text-muted">{{ $contact->company?->name ?: '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Tab: Destinataires (tous les envois) ─────────────────────────── --}}
    <div class="tab-pane fade" id="campaign_destinataires" role="tabpanel">
        @include('backend.contents.campaigns.partials._destinataires-tab')
    </div>

    {{--
        Tab Général is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
