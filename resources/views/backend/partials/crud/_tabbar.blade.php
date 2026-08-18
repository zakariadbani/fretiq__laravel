{{--
    Generic @include partial: crud tabbar — renders <x-crud.hero> with embedded tab nav.

    Usage:
        @include('backend.partials.crud._tabbar', [
            'model'       => $model,
            'currentPage' => 'view',   // 'view' | 'edit'
            'config'      => $viewConfig,
            'heroProps'   => [],       // optional pre-computed overrides merged into hero props
        ])

    Derives hero props from $config. $heroProps (optional) may override any key.

    Config shape (see App\Crud\ViewConfigs\*):
        route_base      (string)   — route name base, e.g. 'admin.companies'
        route_base_id   (string)   — pane id prefix, e.g. 'company'
        permission      (string)   — entity permission string
        title           (string)   — hero title
        avatar          (array)    — passed to <x-crud.hero>
        badges          (array)    — passed to <x-crud.hero>
        subtitle        (array)    — passed to <x-crud.hero>
        tiles           (array)    — passed to <x-crud.hero>
        toggle          (array|null) — statusBar config; null = omit
        tabs            (array)    — tab descriptors (see below)

    Tab descriptor shape:
        key         (string)      — pane id suffix
        label       (string)      — tab label
        icon        (string)      — Bootstrap Icons class
        mode        (string)      — 'view' | 'edit' | 'both'
        count       (int|null)    — optional badge count
        out_of_form (bool)        — marks pane as relocated by crud-tabs.js
--}}

@php
    $hasId  = isset($model) && $model && $model->id;

    // Merge heroProps overrides into config-derived props
    $hp = $heroProps ?? [];

    $heroTitle     = $hp['title']     ?? ($config['title']     ?? '');
    $heroAvatar    = $hp['avatar']    ?? ($config['avatar']    ?? null);
    $heroBadges    = $hp['badges']    ?? ($config['badges']    ?? []);
    $heroSubtitle  = $hp['subtitle']  ?? ($config['subtitle']  ?? []);
    $heroTiles     = $hp['tiles']     ?? ($config['tiles']     ?? []);
    // Allow callers to explicitly suppress the inherited config toggle by
    // passing ['statusBar' => null]. Null-coalescing would otherwise fall back
    // to $config['toggle'] and make the override impossible.
    $heroStatusBar = array_key_exists('statusBar', $hp)
        ? $hp['statusBar']
        : ($config['toggle'] ?? null);

    $routeBase   = $config['route_base']    ?? '';
    $routeBaseId = $config['route_base_id'] ?? '';
    $tabs        = $config['tabs']          ?? [];

    // Determine which tab is the "first native" on this page (the active tab).
    // A tab is native when its mode matches $currentPage exactly (or mode is
    // 'both'). $currentPage must equal one of the tab mode values; unknown
    // values make every tab a route-link.
    $activeKey = null;
    foreach ($tabs as $tab) {
        $mode   = $tab['mode'] ?? 'view';
        $native = ($mode === 'both') || ($mode === ($currentPage ?? 'view'));
        if ($native) {
            $activeKey = $tab['key'];
            break;
        }
    }
@endphp

<x-crud.hero
    :model="$model"
    :title="$heroTitle"
    :avatar="$heroAvatar"
    :badges="$heroBadges"
    :subtitle="$heroSubtitle"
    :tiles="$heroTiles"
    :statusBar="$heroStatusBar"
>

    {{-- Action buttons slot --}}
    @isset($actions)
        <x-slot:actions>{!! $actions !!}</x-slot:actions>
    @endisset

    {{-- Tab nav --}}
    <ul class="nav nav-line-tabs nav-line-tabs-2x border-transparent fs-6 fw-bold flex-wrap gap-2 gap-md-0" role="tablist">

        @foreach($tabs as $tab)
            @php
                $key    = $tab['key'];
                $mode   = $tab['mode'] ?? 'view';
                $paneId = $routeBaseId . '_' . $key;
                $label  = $tab['label'] ?? $key;
                $icon   = $tab['icon']  ?? '';
                $count  = $tab['count'] ?? null;

                $native = ($mode === 'both') || ($mode === ($currentPage ?? 'view'));

                $isActive = ($key === $activeKey);

                // Deep-link destination when NOT native on this page — append the pane
                // hash so the target page opens on the right tab.
                if (!$native) {
                    $linkRoute = $hasId ? (route($routeBase . '.' . $mode, $model->id) . '#' . $paneId) : '#';
                }

                // Skip view-only tabs on create (no model id yet)
                $skip = !$hasId && $mode === 'view';
            @endphp

            @if(!$skip)
                <li class="nav-item mt-1">
                    @if($native)
                        <a class="nav-link text-active-primary ms-0 me-4 me-md-8 py-3 py-md-4 px-1{{ $isActive ? ' active' : '' }}"
                           id="tab_{{ $paneId }}"
                           data-bs-toggle="tab"
                           role="tab"
                           aria-controls="{{ $paneId }}"
                           aria-selected="{{ $isActive ? 'true' : 'false' }}"
                           tabindex="{{ $isActive ? '0' : '-1' }}"
                           href="#{{ $paneId }}">
                            <i class="bi {{ $icon }} me-1"></i>
                            {{ $label }}
                            @if(!is_null($count) && $hasId)
                                <span class="badge badge-light-primary ms-2 crud-count-badge {{ $key }}-count-badge"
                                      data-count-for="{{ $key }}">{{ $count }}</span>
                            @endif
                        </a>
                    @else
                        <a class="nav-link crud-route-link text-gray-700 text-hover-primary ms-0 me-4 me-md-8 py-3 py-md-4 px-1"
                           data-crud-route-link="true"
                           aria-label="Ouvrir {{ $label }} sur une autre page"
                           href="{{ $linkRoute }}">
                            <i class="bi {{ $icon }} me-1"></i>
                            {{ $label }}
                            @if(!is_null($count) && $hasId)
                                <span class="badge badge-light-primary ms-2 crud-count-badge {{ $key }}-count-badge"
                                      data-count-for="{{ $key }}">{{ $count }}</span>
                            @endif
                        </a>
                    @endif
                </li>
            @endif

        @endforeach

    </ul>

</x-crud.hero>

@once
    @push('styles')
        <style>
            .crud-route-link {
                border-bottom-style: dotted !important;
            }
        </style>
    @endpush
@endonce
