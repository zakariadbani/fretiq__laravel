<x-default-layout>

@section('title')
    Contact — {{ e($model->name) }}
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
            <a href="{{ route('admin.contacts.index') }}" class="text-muted text-hover-primary">Contacts</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ e($model->name) }}</li>
    </ul>
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

                        <div class="row mb-0">
                            <label class="col-lg-5 fw-bold text-muted">Vérification email</label>
                            <div class="col-lg-7">
                                <span class="fw-semibold">{{ $model->email_verification_status ?: '—' }}</span>
                            </div>
                        </div>

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
