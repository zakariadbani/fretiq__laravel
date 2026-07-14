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

            {{-- ── Nav tabs ──────────────────────────────────────────────────── --}}
            <ul class="nav nav-tabs nav-line-tabs mb-5 fs-6" id="settingsNav" role="tablist">
                @foreach ($tabs as $tabKey => $tabConfig)
                    <li class="nav-item" role="presentation">
                        @if ($tabConfig['enabled'] ?? false)
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
                        @else
                            <a
                                class="nav-link disabled text-muted"
                                tabindex="-1"
                                aria-disabled="true"
                                href="#"
                            >
                                {{ $tabConfig['label'] }}
                                <span class="badge badge-light-secondary ms-1 fs-9">À venir</span>
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
            {{-- end Nav tabs --}}

            {{-- ── Tab panes ─────────────────────────────────────────────────── --}}
            <div class="tab-content" id="settingsTabContent">
                @foreach ($tabs as $tabKey => $tabConfig)
                    <div
                        class="tab-pane fade {{ ($tabConfig['enabled'] ?? false) && $loop->first ? 'show active' : '' }}"
                        id="kt_tab_{{ $tabKey }}"
                        role="tabpanel"
                        aria-labelledby="nav-{{ $tabKey }}-tab"
                    >
                        @if ($tabConfig['enabled'] ?? false)

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

                        @else

                            {{-- Placeholder for disabled tabs --}}
                            <div class="alert alert-info">
                                <i class="bi bi-clock me-2"></i>
                                @if (! empty($tabConfig['description']))
                                    {{ $tabConfig['description'] }}
                                @else
                                    Cette section sera disponible dans une prochaine version.
                                @endif
                            </div>

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
    });
}());
</script>
@endpush

</x-default-layout>
