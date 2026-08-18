<x-default-layout>

@section('title')
    Paramètres
@endsection

@section('breadcrumbs')
    <x-crud.breadcrumb :items="[['label' => 'Administration'], ['label' => 'Paramètres']]" />
@endsection

{{-- ── Settings form ────────────────────────────────────────────────────────── --}}
<form action="{{ route('admin.settings.save') }}" method="POST" class="form">
    @csrf
    <input type="hidden" name="active_tab" id="active_tab" value="decouverte">

    <div class="card">

        {{-- Card header --}}
        <div class="card-header">
            <div class="card-title">
                <h2>Configuration</h2>
            </div>
        </div>

        {{-- Card body --}}
        <div class="card-body">

            @php
                $enabledTabs = collect($tabs)->filter(fn (array $tabConfig): bool => $tabConfig['enabled'] ?? false);
            @endphp

            {{-- ── Nav tabs ──────────────────────────────────────────────────── --}}
            <ul class="nav nav-tabs nav-line-tabs mb-5 fs-6" id="settingsNav" role="tablist">
                @foreach ($enabledTabs as $tabKey => $tabConfig)
                    <li class="nav-item" role="presentation">
                        <a
                            class="nav-link {{ $loop->first ? 'active' : '' }}"
                            id="nav-{{ $tabKey }}-tab"
                            data-bs-toggle="tab"
                            href="#kt_tab_{{ $tabKey }}"
                            role="tab"
                            aria-controls="kt_tab_{{ $tabKey }}"
                            aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        >
                            {{ $tabConfig['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
            {{-- end Nav tabs --}}

            {{-- ── Tab panes ─────────────────────────────────────────────────── --}}
            <div class="tab-content" id="settingsTabContent">
                @foreach ($enabledTabs as $tabKey => $tabConfig)
                    <div
                        class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                        id="kt_tab_{{ $tabKey }}"
                        role="tabpanel"
                        aria-labelledby="nav-{{ $tabKey }}-tab"
                    >
                        {{-- Description --}}
                        @if (! empty($tabConfig['description']))
                            <p class="text-muted mb-6">{{ $tabConfig['description'] }}</p>
                        @endif

                        {{-- Fields --}}
                        @foreach ($tabConfig['fields'] as $fieldKey => $fieldDef)
                            @php
                                $storedValue = $settings["{$tabKey}.{$fieldKey}"] ?? ($fieldDef['default'] ?? null);
                            @endphp
                            @include(
                                'backend.contents.settings.fields.' . $fieldDef['type'],
                                [
                                    'group' => $tabKey,
                                    'key'   => $fieldKey,
                                    'field' => $fieldDef,
                                    'value' => $storedValue,
                                ]
                            )
                        @endforeach

                        @if($tabKey === 'delivrabilite')
                            @can('edit settings')
                                @can('verify contacts')
                                    <div class="separator my-8"></div>
                                    <div class="rounded border border-dashed border-gray-300 p-6" data-email-verification-batch>
                                        <h3 class="fs-5 mb-2">Première vérification groupée</h3>
                                        <p class="text-muted mb-4">L'estimation est locale et ne déclenche aucun appel externe. Seuls les contacts jamais vérifiés seront mis en file après confirmation.</p>
                                        <div class="alert alert-light-primary d-none" data-email-verification-estimate></div>
                                        <button type="button" class="btn btn-light-primary me-2" data-email-verification-estimate-button>
                                            Estimer
                                        </button>
                                        <button type="button" class="btn btn-primary d-none" data-email-verification-run-button>
                                            Confirmer et mettre en file
                                        </button>
                                    </div>
                                @endcan
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
            {{-- end Tab panes --}}

        </div>
        {{-- end Card body --}}

        {{-- Card footer — submit button, gated on edit settings --}}
        @can('edit settings')
            <div class="card-footer d-flex justify-content-end py-6 px-9">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2 me-1"></i>
                    Enregistrer
                </button>
            </div>
        @endcan

    </div>
    {{-- end Card --}}

</form>

@push('scripts')
<script>
(function () {
    'use strict';

    var activeTabInput = document.getElementById('active_tab');

    // ── Restore active tab from location.hash on page load ─────────────────────
    function activateTabFromHash() {
        var hash = window.location.hash;
        if (!hash) return;

        var tabId = hash.slice(1); // strip leading '#'
        var pane  = document.getElementById(tabId);
        if (!pane) return;

        // Find the corresponding nav link and activate it via Bootstrap
        var navLink = document.querySelector('#settingsNav a[href="' + hash + '"]');
        if (navLink && !navLink.classList.contains('disabled')) {
            var tab = new bootstrap.Tab(navLink);
            tab.show();
        }
    }

    // ── Keep active_tab hidden input in sync ────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        activateTabFromHash();

        var navLinks = document.querySelectorAll('#settingsNav a[data-bs-toggle="tab"]');
        navLinks.forEach(function (link) {
            link.addEventListener('shown.bs.tab', function (e) {
                var href   = e.target.getAttribute('href') || '';
                var tabKey = href.replace('#kt_tab_', '');
                if (activeTabInput) {
                    activeTabInput.value = tabKey;
                }
            });
        });

        var batch = document.querySelector('[data-email-verification-batch]');
        if (!batch) return;
        var estimateButton = batch.querySelector('[data-email-verification-estimate-button]');
        var runButton = batch.querySelector('[data-email-verification-run-button]');
        var output = batch.querySelector('[data-email-verification-estimate]');
        var estimate = null;

        function render(data) {
            estimate = data;
            output.textContent = data.eligible + ' contact(s) à vérifier · coût estimé : ' + data.estimated_cost + ' crédit(s) · déjà vérifiés : ' + data.exclusions.already_verified + ' · supprimés/exclus : ' + data.exclusions.suppressed;
            output.classList.remove('d-none');
            runButton.classList.toggle('d-none', data.eligible === 0);
        }

        estimateButton.addEventListener('click', async function () {
            var response = await fetch(@json(route('admin.settings.email-verification.estimate')), {headers: {'Accept': 'application/json'}});
            if (response.ok) render(await response.json());
        });

        runButton.addEventListener('click', async function () {
            if (!estimate || !window.confirm('Mettre ' + estimate.eligible + ' contact(s) en file de vérification ?')) return;
            var response = await fetch(@json(route('admin.settings.email-verification.run')), {
                method: 'POST',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
                body: JSON.stringify({confirm: true, expected_count: estimate.eligible})
            });
            var data = await response.json();
            if (response.ok) {
                output.textContent = data.queued + ' contact(s) mis en file.';
                runButton.classList.add('d-none');
            } else if (data.estimate) {
                render(data.estimate);
            } else {
                output.textContent = data.message || 'Impossible de lancer la vérification.';
                output.classList.remove('d-none');
            }
        });
    });
}());
</script>
@endpush

</x-default-layout>
