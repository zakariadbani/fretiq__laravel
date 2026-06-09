{{--
    Generic anonymous Blade component: <x-crud.stat-tile color="success" value="Prospect" caption="Statut" icon="bi-patch-check" />

    Renders a Metronic hero status tile:
        bg-light-{color} border-{color} border border-dashed rounded min-w-150px py-3 px-4 me-6 mb-3

    Mirrors the $heroTiles loop in x-companies.hero (lines 87–99).
    When color is 'secondary' the icon and value text fall back to gray classes,
    exactly as the original does.

    Props:
        color   (string)      — Bootstrap / Metronic color token (primary, success, warning, danger, info, secondary…)
        value   (string|null) — main value displayed in the tile; null → muted dash
        caption (string)      — small label below the value
        icon    (string|null) — Bootstrap Icons class (e.g. 'bi-patch-check'); omit to skip icon
--}}
@props([
    'color'   => 'primary',
    'value'   => null,
    'caption' => '',
    'icon'    => null,
])

@php
    // When color is secondary, fall back to gray text classes — same as companies/hero.
    $txtClass  = ($color === 'secondary') ? 'text-gray-800' : 'text-' . $color;
    $iconClass = ($color === 'secondary') ? 'text-gray-500' : 'text-' . $color;

    $displayValue = $value ?? '—';
@endphp

<div class="bg-light-{{ $color }} border-{{ $color }} border border-dashed rounded min-w-150px py-3 px-4 me-6 mb-3">
    <div class="d-flex align-items-center">
        @if($icon)
            <i class="bi {{ $icon }} fs-2 {{ $iconClass }} me-2"></i>
        @endif
        <div class="fs-3 fw-bolder {{ $txtClass }}">{{ $displayValue }}</div>
    </div>
    @if($caption)
        <div class="fw-semibold fs-6 text-gray-500">{{ $caption }}</div>
    @endif
</div>
