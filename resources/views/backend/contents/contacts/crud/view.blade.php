<x-default-layout>

@section('title')
    Contact — {{ $model->name }}
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Contacts', 'route' => 'admin.contacts.index'], ['label' => $model->name]]" />
@endsection

@section('toolbar_actions')
    @include('backend.elements.form-actions', ['variant' => 'toolbar', 'backRoute' => 'admin.contacts.index'])
@endsection

{{--
    Contact view — unified 2-tab UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: contact_apercu (view) / contact_general (cross-link to edit).
    Aperçu is native on the view page; Général deep-links to edit.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.contacts.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="contact_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\ContactViewConfig::make($model, $stats ?? null),
        ])

        {{-- Compliance / RGPD block — preserved below the generic apercu --}}
        <div class="row g-6 g-xl-9 mt-4">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header border-0 pt-5">
                        <h3 class="card-title fw-bolder m-0">
                            <i class="bi bi-shield-check text-success fs-3 me-2"></i>
                            Conformité RGPD
                        </h3>
                    </div>
                    <div class="card-body border-top">

                        <div class="row mb-7">
                            <label class="col-lg-5 fw-bold text-muted">Date consentement</label>
                            <div class="col-lg-7">
                                <span class="fw-semibold">
                                    {{ $model->consent_at ? $model->consent_at->format('d/m/Y H:i') : '—' }}
                                </span>
                            </div>
                        </div>

                        @php
                            $verificationBadge = app(\App\Services\Campaign\ContactEligibilityService::class)->badge($model);
                            $verificationSource = config(
                                'global.data.contact_email_verification_sources.'.$model->email_verification_source
                            );
                        @endphp

                        <div class="row mb-7">
                            <label class="col-lg-5 fw-bold text-muted">Vérification email</label>
                            <div class="col-lg-7">
                                <span class="badge badge-light-{{ $verificationBadge['color'] }}">
                                    {{ $verificationBadge['label'] }}
                                </span>
                            </div>
                        </div>

                        <div class="row mb-7">
                            <label class="col-lg-5 fw-bold text-muted">Vérifié le</label>
                            <div class="col-lg-7 fw-semibold">
                                {{ $model->email_verification_checked_at?->format('d/m/Y H:i') ?? '—' }}
                            </div>
                        </div>

                        <div class="row mb-0">
                            <label class="col-lg-5 fw-bold text-muted">Preuve</label>
                            <div class="col-lg-7 fw-semibold">{{ $verificationSource ?: '—' }}</div>
                        </div>

                        @if($model->email_verification_status === 'accept_all')
                            <div class="alert alert-warning mt-7 mb-0" role="status">
                                Adresse accept-all : envoi autorisé uniquement si le retour de livraison est opérationnel.
                            </div>
                        @endif

                    </div>
                </div>
            </div>
        </div>
        {{-- end RGPD block --}}

    </div>
    {{-- end Aperçu --}}

    {{--
        Tab 2 (Général) is NOT a native pane here —
        it deep-links to the edit page via the tab nav. No pane div needed.
    --}}

</div>
{{-- end tab-content --}}

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
@endpush

</x-default-layout>
