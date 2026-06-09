{{--
    Generic anonymous Blade component: KPI stat card (2×2 grid style).

    Usage:
        <x-crud.stat-card icon="bi-people" color="primary" label="Contacts" :value="$stats['contacts_total']" />
        <x-crud.stat-card icon="bi-envelope" color="info" label="Emails envoyés" :value="null" hint="Disponible après la 1re campagne" />

    Mirrors the 2×2 stat card blocks in _apercu-tab.blade.php (lines 123–196).

    Empty state: when value is null, shows gray $empty text in the big-value slot
    and the $hint as muted subtext — honest, no fake zeroes.
    When value is set, shows it bold in text-gray-900.

    Props:
        icon   (string|null)  — Bootstrap Icons class (e.g. 'bi-people')
        color  (string)       — Metronic color token for the symbol background; default 'primary'
        label  (string)       — small label below the value
        value  (*|null)       — KPI value; null triggers empty state
        hint   (string|null)  — muted sub-text shown in empty state (or always if set)
        empty  (string)       — placeholder shown when value is null; default '—'
--}}
@props([
    'icon'  => null,
    'color' => 'primary',
    'label' => '',
    'value' => null,
    'hint'  => null,
    'empty' => '—',
])

<div class="card h-100">
    <div class="card-body py-5 px-7">
        <div class="d-flex align-items-center gap-4">
            @if($icon)
                <div class="symbol symbol-50px">
                    <div class="symbol-label bg-light-{{ $color }}">
                        <i class="bi {{ $icon }} fs-2 text-{{ $color }}"></i>
                    </div>
                </div>
            @endif
            <div>
                @if($value !== null)
                    <div class="fs-2 fw-bold text-gray-900">{{ $value }}</div>
                @else
                    <div class="fs-2 fw-bold text-gray-400">{{ $empty }}</div>
                @endif
                <div class="fw-semibold text-muted fs-6">{{ $label }}</div>
                @if($hint)
                    <div class="text-muted fs-7 mt-1">{{ $hint }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
