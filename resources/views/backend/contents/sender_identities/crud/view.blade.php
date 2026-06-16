<x-default-layout>

@section('title')
    Identité — {{ $model->name }}
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
            <a href="{{ route('admin.sender_identities.index') }}" class="text-muted text-hover-primary">Identités d'expéditeur</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ $model->name }}</li>
    </ul>
@endsection

{{--
    SenderIdentity view — hero + tabbar + aperçu contract.
    Tab pane IDs: sender_apercu / sender_general.
    Aperçu is native (default active); Général deep-links to edit.
    is_default is shown as a hero tile (Par défaut badge), not a second status-bar.
    is_active is the hero status-bar toggle.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.sender_identities.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="sender_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\SenderIdentityViewConfig::make($model),
        ])

        {{-- Signature HTML card — preserved from original view, placed inside aperçu pane --}}
        @if($model->signature_html)
        <div class="card mt-5">
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fw-bolder m-0">
                    <i class="bi bi-code-slash text-info fs-3 me-2"></i>
                    Signature HTML
                </h3>
            </div>
            <div class="card-body border-top">
                <div class="mb-4">
                    <div class="fs-7 text-muted fw-semibold mb-2">Aperçu</div>
                    <iframe
                        srcdoc="{{ $model->signature_html }}"
                        class="w-100 border-0 rounded"
                        style="min-height: 120px;"
                        sandbox="allow-same-origin"
                        title="Aperçu de la signature"></iframe>
                </div>
                <div>
                    <div class="fs-7 text-muted fw-semibold mb-2">Code HTML</div>
                    <pre class="bg-white border rounded p-4 mb-0 fs-8 text-gray-700" style="white-space: pre-wrap; word-break: break-all; max-height: 200px; overflow-y: auto;">{{ $model->signature_html }}</pre>
                </div>
            </div>
        </div>
        @endif
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
@endpush

</x-default-layout>
