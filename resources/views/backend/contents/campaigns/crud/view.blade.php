<x-default-layout>

@section('title')
    Campagne — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <ul class="breadcrumb breadcrumb-separatorless fw-semibold fs-7 my-0 pt-1">
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.dashboard') }}" class="text-muted text-hover-primary">Accueil</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">
            <a href="{{ route('admin.campaigns.index') }}" class="text-muted text-hover-primary">Campagnes</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ $model->name }}</li>
    </ul>
@endsection

{{--
    Campaign view — hero+tabbar UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: campaign_apercu / campaign_historique / campaign_destinataires.
    campaign_general deep-links to the edit page (no native pane here).
    Aperçu is the default active pane (native on view page).

    Tab layout:
      1. Aperçu    — _apercu partial + sequence enrolled card + latest-run KPI cards
      2. Général   — cross-route link to edit page
      3. Historique — @include(_historique-tab)  full execution history table
      4. Destinataires — @include(_destinataires-tab)  all recipients across all runs (paginated)

    Schedule / SendNow / Planifier buttons: preserved verbatim in _header-actions (hero slot).
    All @can('send campaigns') gates are kept exactly as the original.
    JS handlers (Swal + axios) are kept verbatim in @push('scripts').
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.campaigns.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="campaign_apercu" role="tabpanel">

        {{-- Generic apercu: details table (left) + stat cards + charts (right) --}}
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\CampaignViewConfig::make($model, $stats ?? null, $recipientsTotal ?? null),
        ])

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

        </div>
        @endif

        {{-- ── Preserved: KPIs from latest run ──────────────────────────── --}}
        @if($latestRun)
        <div class="row g-4 mt-2">

            {{-- Envoyés --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestRun->stats_sent ?? 0) }}</span>
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
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ $latestRun->openRate() }}%</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Taux d'ouverture</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">{{ number_format($latestRun->stats_opened ?? 0) }} ouverts / {{ number_format($latestRun->stats_delivered ?? 0) }} délivrés</span>
                    </div>
                </div>
            </div>

            {{-- Taux de clic --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ $latestRun->clickRate() }}%</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Taux de clic</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">{{ number_format($latestRun->stats_clicked ?? 0) }} clics</span>
                    </div>
                </div>
            </div>

            {{-- Réponses --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestRun->stats_replied ?? 0) }}</span>
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
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ $latestRun->conversionRate() }}%</span>
                            <span class="text-gray-500 pt-1 fw-semibold fs-6">Conversion</span>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <span class="text-muted fs-8">{{ number_format($latestRun->conversion_count ?? 0) }} conversion(s)</span>
                    </div>
                </div>
            </div>

            {{-- Rebonds --}}
            <div class="col-sm-6 col-xl-4">
                <div class="card card-flush h-lg-100">
                    <div class="card-header pt-5">
                        <div class="card-title d-flex flex-column">
                            <span class="fs-2hx fw-bold text-gray-900 me-2 lh-1 ls-n2">{{ number_format($latestRun->stats_bounced ?? 0) }}</span>
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
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // ── Schedule button ──────────────────────────────────────────────
            const btnSchedule = document.getElementById('btn-schedule');
            if (btnSchedule) {
                btnSchedule.addEventListener('click', function () {
                    const url = this.dataset.url;
                    this.disabled = true;

                    Swal.fire({
                        title: 'Planifier la campagne ?',
                        text: 'La campagne sera placée en file d\'attente pour l\'envoi à la date planifiée.',
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: 'Planifier',
                        cancelButtonText: 'Annuler',
                        buttonsStyling: false,
                        customClass: {
                            confirmButton: 'btn btn-info me-2',
                            cancelButton: 'btn btn-light',
                        },
                    }).then((result) => {
                        if (result.isConfirmed) {
                            axios.post(url, { _token: '{{ csrf_token() }}' })
                                .then(function (r) {
                                    if (r.data.redirect) {
                                        window.location.replace(r.data.redirect);
                                    } else {
                                        window.location.reload();
                                    }
                                })
                                .catch(function (err) {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Erreur',
                                        text: err.response?.data?.message || 'Une erreur est survenue.',
                                        buttonsStyling: false,
                                        confirmButtonText: 'OK',
                                        customClass: { confirmButton: 'btn btn-primary' },
                                    });
                                })
                                .finally(function () {
                                    btnSchedule.disabled = false;
                                });
                        } else {
                            btnSchedule.disabled = false;
                        }
                    });
                });
            }

            // ── Send now button ──────────────────────────────────────────────
            const btnSendNow = document.getElementById('btn-send-now');
            if (btnSendNow) {
                btnSendNow.addEventListener('click', function () {
                    const url          = this.dataset.url;
                    const scheduleType = this.dataset.scheduleType || '';
                    const self         = this;
                    self.disabled = true;

                    if (scheduleType === 'sequence') {
                        // ── Sequence branch: fetch eligible count then confirm ────
                        @if($model->segment_id)
                        axios.get('{{ route("admin.campaigns.segmentCount", $model->segment_id) }}')
                            .then(function (r) {
                                const eligibleCount = r.data.count || 0;
                                Swal.fire({
                                    title: 'Démarrer la séquence ?',
                                    html: 'Démarrer la séquence pour ~<strong>' + eligibleCount + '</strong> contact(s) éligible(s).<br>'
                                        + '<span class="text-muted fs-7">Les contacts déjà inscrits seront ignorés.</span>',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonText: 'Démarrer',
                                    cancelButtonText: 'Annuler',
                                    buttonsStyling: false,
                                    customClass: {
                                        confirmButton: 'btn btn-success me-2',
                                        cancelButton: 'btn btn-light',
                                    },
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        axios.post(url, { _token: '{{ csrf_token() }}' })
                                            .then(function (r) {
                                                if (r.data.redirect) {
                                                    window.location.replace(r.data.redirect);
                                                } else {
                                                    window.location.reload();
                                                }
                                            })
                                            .catch(function (err) {
                                                Swal.fire({
                                                    icon: 'error',
                                                    title: 'Erreur',
                                                    text: err.response?.data?.text || err.response?.data?.message || 'Une erreur est survenue.',
                                                    buttonsStyling: false,
                                                    confirmButtonText: 'OK',
                                                    customClass: { confirmButton: 'btn btn-primary' },
                                                });
                                            })
                                            .finally(function () {
                                                self.disabled = false;
                                            });
                                    } else {
                                        self.disabled = false;
                                    }
                                });
                            })
                            .catch(function () {
                                // If count fetch fails, show confirm without count
                                Swal.fire({
                                    title: 'Démarrer la séquence ?',
                                    text: 'Les contacts éligibles du segment seront inscrits dans la séquence.',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonText: 'Démarrer',
                                    cancelButtonText: 'Annuler',
                                    buttonsStyling: false,
                                    customClass: {
                                        confirmButton: 'btn btn-success me-2',
                                        cancelButton: 'btn btn-light',
                                    },
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        axios.post(url, { _token: '{{ csrf_token() }}' })
                                            .then(function (r) {
                                                if (r.data.redirect) {
                                                    window.location.replace(r.data.redirect);
                                                } else {
                                                    window.location.reload();
                                                }
                                            })
                                            .catch(function (err) {
                                                Swal.fire({
                                                    icon: 'error',
                                                    title: 'Erreur',
                                                    text: err.response?.data?.text || err.response?.data?.message || 'Une erreur est survenue.',
                                                    buttonsStyling: false,
                                                    confirmButtonText: 'OK',
                                                    customClass: { confirmButton: 'btn btn-primary' },
                                                });
                                            })
                                            .finally(function () { self.disabled = false; });
                                    } else {
                                        self.disabled = false;
                                    }
                                });
                            });
                        @else
                        // No segment attached — show simple confirm
                        Swal.fire({
                            title: 'Démarrer la séquence ?',
                            text: 'Les contacts éligibles du segment seront inscrits dans la séquence.',
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: 'Démarrer',
                            cancelButtonText: 'Annuler',
                            buttonsStyling: false,
                            customClass: {
                                confirmButton: 'btn btn-success me-2',
                                cancelButton: 'btn btn-light',
                            },
                        }).then((result) => {
                            if (result.isConfirmed) {
                                axios.post(url, { _token: '{{ csrf_token() }}' })
                                    .then(function (r) {
                                        if (r.data.redirect) {
                                            window.location.replace(r.data.redirect);
                                        } else {
                                            window.location.reload();
                                        }
                                    })
                                    .catch(function (err) {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Erreur',
                                            text: err.response?.data?.text || err.response?.data?.message || 'Une erreur est survenue.',
                                            buttonsStyling: false,
                                            confirmButtonText: 'OK',
                                            customClass: { confirmButton: 'btn btn-primary' },
                                        });
                                    })
                                    .finally(function () { self.disabled = false; });
                            } else {
                                self.disabled = false;
                            }
                        });
                        @endif
                    } else {
                        // ── One-shot / recurring branch (original behavior) ──────
                        Swal.fire({
                            title: 'Envoyer maintenant ?',
                            html: 'La campagne sera envoyée <strong>immédiatement</strong> aux contacts du segment.<br>Cette action ne peut pas être annulée.',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Envoyer',
                            cancelButtonText: 'Annuler',
                            buttonsStyling: false,
                            customClass: {
                                confirmButton: 'btn btn-success me-2',
                                cancelButton: 'btn btn-light',
                            },
                        }).then((result) => {
                            if (result.isConfirmed) {
                                axios.post(url, { _token: '{{ csrf_token() }}' })
                                    .then(function (r) {
                                        if (r.data.redirect) {
                                            window.location.replace(r.data.redirect);
                                        } else {
                                            window.location.reload();
                                        }
                                    })
                                    .catch(function (err) {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Erreur',
                                            text: err.response?.data?.message || 'Une erreur est survenue.',
                                            buttonsStyling: false,
                                            confirmButtonText: 'OK',
                                            customClass: { confirmButton: 'btn btn-primary' },
                                        });
                                    })
                                    .finally(function () {
                                        self.disabled = false;
                                    });
                            } else {
                                self.disabled = false;
                            }
                        });
                    }
                });
            }
        });
    </script>
@endpush

</x-default-layout>
