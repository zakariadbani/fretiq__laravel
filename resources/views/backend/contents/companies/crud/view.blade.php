<x-default-layout>

@section('title')
    Entreprise — {{ $model->name }}
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
            <a href="{{ route('admin.companies.index') }}" class="text-muted text-hover-primary">Entreprises</a>
        </li>
        <li class="breadcrumb-item">
            <span class="bullet bg-gray-500 w-5px h-2px"></span>
        </li>
        <li class="breadcrumb-item text-muted">{{ $model->name }}</li>
    </ul>
@endsection

{{--
    Company view — unified 5-tab UX (clic2loc parity).
    Hero card + shared tab strip via _header-with-tabs partial.
    Tab pane IDs: company_apercu / company_general / company_contacts / company_activity / company_enrichment.
    Aperçu is native; Général + Enrichissement deep-link to edit; Contacts + Activité are native toggles.
    enrichment_data rendered with {{ }} only — never {!! !!}.
--}}

{{-- Shared hero + tab nav --}}
@include('backend.contents.companies.partials._header-with-tabs', [
    'model'       => $model,
    'currentPage' => 'view',
])

{{-- Tab content --}}
<div class="tab-content">

    {{-- ── Tab 1: Aperçu (default active on view) ────────────────────────── --}}
    <div class="tab-pane fade show active" id="company_apercu" role="tabpanel">
        @include('backend.partials.crud._apercu', [
            'model'  => $model,
            'config' => \App\Crud\ViewConfigs\CompanyViewConfig::make($model, $stats ?? null),
        ])
    </div>
    {{-- end Aperçu --}}

    {{-- ── Tab 3: Contacts (native toggle on both pages) ────────────────── --}}
    <div class="tab-pane fade" id="company_contacts" role="tabpanel">
        @include('backend.contents.companies.partials._contacts-tab', ['model' => $model])
    </div>
    {{-- end Contacts --}}

    {{-- ── Tab 4: Activité (native toggle on both pages) ────────────────── --}}
    <div class="tab-pane fade" id="company_activity" role="tabpanel">
        @include('backend.contents.companies.partials._activity-tab', ['model' => $model])
    </div>
    {{-- end Activité --}}

    {{--
        Tabs 2 (Général) and 5 (Enrichissement) are NOT native panes here —
        they deep-link to the edit page via the tab nav. No pane divs needed.
    --}}

</div>
{{-- end tab-content --}}

{{-- Contact modal (for inline create / edit from the Contacts tab) --}}
@include('backend.contents.companies.partials._contact-modal', ['model' => $model])

@push('scripts')
    <script src="{{ asset('assets/js/custom/backend/crud-tabs.js') }}"></script>
    <script src="{{ asset('assets/js/custom/backend/crud-charts.js') }}"></script>
    <script>
        /**
         * enrichCompany — manual enrichment for a single company.
         *
         * Mirrors launchDiscovery() structure from prospect_criteria view.
         * Swal confirm → fetch POST → toastr → reload after 800 ms on success.
         *
         * @param {number} id         Company ID
         * @param {string} csrfToken  Laravel CSRF token
         */
        window.enrichCompany = function (id, csrfToken) {
            Swal.fire({
                title: 'Récupérer les contacts ?',
                text: "Cette action consommera 1 crédit de découverte.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Récupérer',
                cancelButtonText: 'Annuler',
                confirmButtonColor: '#009ef7',
            }).then(function (result) {
                if (!result.isConfirmed) return;

                // Disable trigger button during flight.
                var btn = document.querySelector('[onclick*="enrichCompany"]');
                if (btn) btn.disabled = true;

                fetch('/admin/companies/' + id + '/enrich', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({}),
                })
                .then(function (response) {
                    var httpStatus = response.status;
                    return response.text().then(function (text) {
                        var data = {};
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            // Non-JSON response (e.g. 419 session expired)
                            if (btn) btn.disabled = false;
                            toastr.error("Une erreur est survenue — réessayez.", 'Erreur');
                            return;
                        }

                        if (httpStatus === 200) {
                            toastr.success(data.text || 'Enrichissement réussi.', 'Succès');
                            setTimeout(function () { window.location.reload(); }, 800);
                        } else if (httpStatus === 409) {
                            toastr.error(data.text || 'Enrichissement déjà en cours.', 'En cours');
                            if (btn) btn.disabled = false;
                        } else if (httpStatus === 422) {
                            toastr.error(data.text || 'Enrichissement impossible.', 'Erreur');
                            if (btn) btn.disabled = false;
                        } else {
                            toastr.error(data.text || 'Une erreur est survenue.', 'Erreur');
                            if (btn) btn.disabled = false;
                        }
                    });
                })
                .catch(function () {
                    if (btn) btn.disabled = false;
                    toastr.error("Une erreur est survenue — réessayez.", 'Erreur');
                });
            });
        };
    </script>
@endpush

</x-default-layout>
