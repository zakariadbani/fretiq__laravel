{{--
    Generic anonymous Blade component: ApexCharts container with honest empty-state.

    Usage (with data):
        <x-crud.chart
            id="company_contacts_donut"
            title="Contacts par statut"
            type="donut"
            :series="$stats['contacts_donut']['series']"
            :labels="$stats['contacts_donut']['labels']"
            :colors="$stats['contacts_donut']['colors']"
            :showTotal="true"
        />

    Usage (empty state rendered automatically when series is empty):
        <x-crud.chart id="company_funnel" title="Engagement e-mail" empty="Disponible après le lancement des campagnes" empty-icon="bi-bar-chart" />

    Empty state: a card with centered icon + muted message. No <script> tag emitted.
    Data state:  a card with optional title + a <div> bearing data-crud-chart JSON.
                 A shared crud-charts.js reads [data-crud-chart] and initialises ApexCharts.
                 Do NOT inline an init script here.

    Props:
        id          (string)  — DOM id for the chart container element
        title       (string)  — card title; omit for no header
        type        (string)  — ApexCharts chart type token (line, area, bar, donut, pie, radialBar)
        series      (array)   — ApexCharts series array; empty triggers empty state
        categories  (array)   — x-axis categories for bar/line/area charts
        labels      (array)   — segment labels for donut/pie/radialBar
        colors      (array)   — per-segment hex colors (empty = use color token)
        options     (array)   — raw ApexCharts options deep-merged over the base (per-chart overrides)
        height      (int)     — chart container min-height in px; default 300
        color       (string)  — Metronic color token hint; default 'primary'
        showTotal   (bool)    — for donut: show centre total label; default false
        hollowSize  (string)  — for radialBar: hollow size %; default '60%'
        empty       (string)  — empty-state message; default 'Données disponibles plus tard'
        emptyIcon   (string)  — Bootstrap Icons class for empty state; default 'bi-bar-chart'
--}}
@props([
    'id'          => '',
    'title'       => '',
    'type'        => 'line',
    'series'      => [],
    'categories'  => [],
    'labels'      => [],
    'colors'      => [],
    'options'     => [],
    'height'      => 300,
    'color'       => 'primary',
    'showTotal'   => false,
    'hollowSize'  => '60%',
    'empty'       => 'Données disponibles plus tard',
    'emptyIcon'   => 'bi-bar-chart',
])

@php
    // "No data" detection:
    // - donut/pie/bar/line/area: empty($series) covers both [] and [[]]
    // - radialBar: empty($series) or series is [null]
    $isEmpty = empty($series)
        || ($type === 'radialBar' && (count($series) === 1 && $series[0] === null));
@endphp

@if($isEmpty)
    {{-- Honest empty state — same visual language as stat-card empty state --}}
    <div class="card">
        @if($title)
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fs-5 fw-bold">{{ $title }}</h3>
            </div>
        @endif
        <div class="card-body py-3">
            <div class="text-center py-10 text-muted" style="min-height: {{ $height }}px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <i class="bi {{ $emptyIcon }} fs-2x mb-3 d-block"></i>
                <p class="fw-semibold fs-6 mb-0">{{ $empty }}</p>
            </div>
        </div>
    </div>
@else
    {{--
        Data state: emit only a container div with the chart config encoded as
        data-crud-chart. The shared crud-charts.js initialises ApexCharts by
        reading this attribute — no inline script here.
    --}}
    <div class="card">
        @if($title)
            <div class="card-header border-0 pt-5">
                <h3 class="card-title fs-5 fw-bold">{{ $title }}</h3>
            </div>
        @endif
        <div class="card-body py-3">
            @php
                $crudChartConfig = [
                    'type'       => $type,
                    'series'     => $series,
                    'categories' => $categories,
                    'labels'     => $labels,
                    'colors'     => $colors,
                    'options'    => $options,
                    'height'     => $height,
                    'color'      => $color,
                    'showTotal'  => $showTotal,
                    'hollowSize' => $hollowSize,
                ];
            @endphp
            <div
                id="{{ $id }}"
                data-crud-chart="{{ json_encode($crudChartConfig) }}"
                style="min-height: {{ $height }}px"
            ></div>
        </div>
    </div>
@endif
