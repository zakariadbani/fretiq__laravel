{{--
    Generic @include partial: aperçu tab pane — details table + KPI stat cards.

    Usage:
        @include('backend.partials.crud._apercu', ['model' => $model, 'config' => $viewConfig])

    Config slices used:
        detail_rows    (array)  — descriptor rows for the left table
        stat_cards     (array)  — KPI stat cards for the right column
        charts         (array)  — ApexCharts descriptors
        quick_actions  (array)  — quick-actions action items

    detail_row descriptor shape (mirrors x-crud.detail-row props):
        label      (string)       — row label
        value      (*)            — field value (already resolved from model)
        type       (string)       — text | badge | enum | link | email | date | boolean | score | tags | raw
        configKey  (string|null)  — for enum type
        color      (string|null)  — for badge/tags
        href       (string|null)  — for link type

    stat_card descriptor shape (mirrors x-crud.stat-card props):
        icon   (string|null)
        color  (string)
        label  (string)
        value  (*|null)
        hint   (string|null)

    chart descriptor shape (mirrors x-crud.chart props):
        id         (string)
        title      (string)
        type       (string)
        series     (array)
        categories (array)
        labels     (array)
        colors     (array)
        options    (array)
        height     (int)
        color      (string)
        showTotal  (bool)
        hollowSize (string)
        empty      (string)
        emptyIcon  (string)

    quick_actions: array of action items (same shape as x-crud.quick-actions 'actions' prop)
--}}

<div class="row g-6 g-xl-9">

    {{-- Left: Details table --}}
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <div class="card-title fs-5 fw-bold">Détails</div>
            </div>
            <div class="card-body p-9">
                <div class="table-responsive">
                    <table class="table align-middle gy-4">
                        <tbody>
                            @foreach($config['detail_rows'] ?? [] as $row)
                                <x-crud.detail-row
                                    :label="$row['label'] ?? ''"
                                    :value="$row['value'] ?? null"
                                    :type="$row['type'] ?? 'text'"
                                    :configKey="$row['configKey'] ?? null"
                                    :color="$row['color'] ?? 'secondary'"
                                    :href="$row['href'] ?? null"
                                    :empty="$row['empty'] ?? '—'"
                                />
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Right: KPI cards + charts + quick actions --}}
    <div class="col-lg-7">
        <div class="row g-5">

            {{-- Stat cards --}}
            @if(!empty($config['stat_cards']))
                <div class="col-12">
                    <div class="row g-5">
                        @foreach($config['stat_cards'] as $card)
                            <div class="col-md-6">
                                <x-crud.stat-card
                                    :icon="$card['icon'] ?? null"
                                    :color="$card['color'] ?? 'primary'"
                                    :label="$card['label'] ?? ''"
                                    :value="$card['value'] ?? null"
                                    :hint="$card['hint'] ?? null"
                                />
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Charts ─────────────────────────────────────────────────────────
                 Layout:
                   • Charts 0 and 1 (donut + radialBar) — side-by-side, col-md-6 each.
                     If ai_score is null buildCharts() emits only 3 charts, so chart[0]
                     occupies col-md-6 and chart[1] starts the next row (funnel, col-12).
                   • Charts 2 and 3 (funnel + area-over-time) — each full-width (col-12).
            --}}
            @if(!empty($config['charts']))
                {{-- Row 1: donut (index 0) + radialBar (index 1) side by side --}}
                <div class="col-12">
                    <div class="row g-5">
                        @foreach($config['charts'] as $idx => $chart)
                            @if($idx === 0 || $idx === 1)
                                <div class="col-md-6">
                                    <x-crud.chart
                                        :id="$chart['id'] ?? ''"
                                        :title="$chart['title'] ?? ''"
                                        :type="$chart['type'] ?? 'line'"
                                        :series="$chart['series'] ?? []"
                                        :categories="$chart['categories'] ?? []"
                                        :labels="$chart['labels'] ?? []"
                                        :colors="$chart['colors'] ?? []"
                                        :options="$chart['options'] ?? []"
                                        :height="$chart['height'] ?? 300"
                                        :color="$chart['color'] ?? 'primary'"
                                        :showTotal="$chart['showTotal'] ?? false"
                                        :hollowSize="$chart['hollowSize'] ?? '60%'"
                                        :empty="$chart['empty'] ?? 'Données disponibles plus tard'"
                                        :emptyIcon="$chart['emptyIcon'] ?? 'bi-bar-chart'"
                                    />
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>

                {{-- Rows 2+: funnel and area-over-time, each full-width --}}
                @foreach($config['charts'] as $idx => $chart)
                    @if($idx >= 2)
                        <div class="col-12">
                            <x-crud.chart
                                :id="$chart['id'] ?? ''"
                                :title="$chart['title'] ?? ''"
                                :type="$chart['type'] ?? 'line'"
                                :series="$chart['series'] ?? []"
                                :categories="$chart['categories'] ?? []"
                                :labels="$chart['labels'] ?? []"
                                :colors="$chart['colors'] ?? []"
                                :options="$chart['options'] ?? []"
                                :height="$chart['height'] ?? 300"
                                :color="$chart['color'] ?? 'primary'"
                                :showTotal="$chart['showTotal'] ?? false"
                                :hollowSize="$chart['hollowSize'] ?? '60%'"
                                :empty="$chart['empty'] ?? 'Données disponibles plus tard'"
                                :emptyIcon="$chart['emptyIcon'] ?? 'bi-bar-chart'"
                            />
                        </div>
                    @endif
                @endforeach
            @endif

            {{-- Quick actions --}}
            @if(!empty($config['quick_actions']))
                <div class="col-12">
                    <x-crud.quick-actions :actions="$config['quick_actions']" />
                </div>
            @endif

        </div>
    </div>

</div>
