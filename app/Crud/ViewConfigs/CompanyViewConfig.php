<?php

namespace App\Crud\ViewConfigs;

use App\Models\Company;

/**
 * ViewConfig descriptor for the Company detail/edit pages.
 *
 * Consumed by:
 *   - CompanyController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 */
class CompanyViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Company|null  $model
     * @param  array|null    $stats  Optional pre-computed stats array from CompanyController::companyStats().
     *                               When provided, real KPI values and chart series are injected into
     *                               stat_cards and charts. When null, stat_cards show empty-state hints.
     */
    public static function make(?Company $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Relationship badge ────────────────────────────────────────────────
        $relCfg   = $hasId ? config('global.data.company_relationships.' . $model->relationship, []) : [];
        $relLabel = $relCfg['label'] ?? null;
        $relColor = $relCfg['color'] ?? 'secondary';

        // ── Hero tiles ────────────────────────────────────────────────────────
        $qsCfg  = $hasId ? config('global.data.company_qualification_statuses.' . $model->qualification_status, []) : [];
        $srcCfg = $hasId ? config('global.data.company_sources.' . $model->source, []) : [];
        $sc     = $hasId ? $model->ai_score : null;
        $scColor = $sc === null ? 'secondary' : ($sc >= 70 ? 'success' : ($sc >= 40 ? 'warning' : 'danger'));

        // ── Subtitle items ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            if ($model->sector) {
                $subtitle[] = ['icon' => 'bi-briefcase', 'text' => $model->sector];
            }
            if ($model->country) {
                $subtitle[] = ['icon' => 'bi-geo-alt', 'text' => strtoupper($model->country)];
            }
            if ($sc !== null) {
                $subtitle[] = ['icon' => 'bi-graph-up', 'text' => 'Score IA : ' . $sc];
            }
        }

        // ── Status-bar toggle (null when no id — omit on create) ─────────────
        $toggle = null;
        if ($hasId) {
            $toggle = [
                'field'       => 'is_active',
                'route'       => route('admin.companies.executeSwitch', $model->id),
                'permission'  => 'edit companies',
                'title'       => 'Entreprise active',
                'description' => 'Inclure cette entreprise dans la prospection active.',
                'success'     => 'Entreprise mise à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        $contactsCount = $hasId
            ? (isset($model->contacts_count) ? $model->contacts_count : optional($model->contacts)->count() ?? 0)
            : null;

        $tabs = [
            ['key' => 'apercu',      'label' => 'Aperçu',         'icon' => 'bi-grid',         'mode' => 'view'],
            ['key' => 'general',     'label' => 'Général',        'icon' => 'bi-building',     'mode' => 'edit'],
            ['key' => 'contacts',    'label' => 'Contacts',       'icon' => 'bi-people',       'mode' => 'both', 'count' => $contactsCount, 'out_of_form' => true],
            ['key' => 'activity',    'label' => 'Activité',       'icon' => 'bi-clock-history', 'mode' => 'both', 'out_of_form' => true],
            ['key' => 'enrichment',  'label' => 'Enrichissement', 'icon' => 'bi-database',     'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Actif',           'value' => $model->is_active,             'type' => 'boolean'],
                ['label' => 'Secteur',         'value' => $model->sector,                'type' => 'text'],
                ['label' => 'Pays',            'value' => $model->country ? strtoupper($model->country) : null, 'type' => 'text'],
                ['label' => 'Taille estimée',  'value' => $model->estimated_size,        'type' => 'text'],
                ['label' => 'Téléphone',       'value' => $model->phone,                 'type' => 'text'],
                ['label' => 'Relation',        'value' => $model->relationship,          'type' => 'enum', 'configKey' => 'company_relationships'],
                ['label' => 'Source',          'value' => $model->source,                'type' => 'enum', 'configKey' => 'company_sources'],
                ['label' => 'Statut qualif.',  'value' => $model->qualification_status,  'type' => 'enum', 'configKey' => 'company_qualification_statuses'],
                ['label' => 'Score IA',        'value' => $model->ai_score,             'type' => 'score'],
                ['label' => 'Créé le',         'value' => $model->created_at,            'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        // When $stats is provided, show real values; otherwise fall back to empty-state hints.
        $statCards = [
            [
                'icon'  => 'bi-people',
                'color' => 'primary',
                'label' => 'Contacts',
                'value' => $stats ? $stats['contacts_total'] : null,
                'hint'  => $stats ? null : null,
            ],
            [
                'icon'  => 'bi-patch-check',
                'color' => 'success',
                'label' => 'Qualifiés',
                'value' => $stats ? $stats['contacts_qualified'] : null,
                'hint'  => $stats ? null : null,
            ],
            [
                'icon'  => 'bi-envelope',
                'color' => 'info',
                'label' => 'Emails envoyés',
                'value' => $stats ? $stats['emails_sent'] : null,
                'hint'  => $stats ? null : 'Disponible après le lancement des campagnes',
            ],
            [
                'icon'  => 'bi-inbox',
                'color' => 'warning',
                'label' => 'Demandes',
                'value' => $stats ? $stats['demandes_total'] : null,
                'hint'  => $stats ? null : 'Disponible après le lancement des campagnes',
            ],
        ];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId && \Illuminate\Support\Facades\Route::has('admin.campaigns.create')) {
            $quickActions[] = [
                'label'      => 'Lancer une campagne',
                'icon'       => 'bi-rocket',
                'color'      => 'light-primary',
                'permission' => 'create campaigns',
                'href'       => route('admin.campaigns.create'),
            ];
        }

        if ($hasId && $model->domain && \Illuminate\Support\Facades\Route::has('admin.companies.enrich')) {
            $quickActions[] = [
                'label'      => 'Récupérer les contacts',
                'icon'       => 'bi-person-plus',
                'color'      => 'light-success',
                'permission' => 'enrich companies',
                'onclick'    => "enrichCompany({$model->id}, '" . csrf_token() . "')",
            ];
        }

        return [
            'route_base'    => 'admin.companies',
            'route_base_id' => 'company',
            'permission'    => 'companies',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'primary',
            ],
            'badges'        => $relLabel ? [['label' => $relLabel, 'color' => $relColor]] : [],
            'subtitle'      => $subtitle,
            'tiles'         => $hasId ? [
                ['icon' => 'bi-patch-check', 'color' => $qsCfg['color']  ?? 'secondary', 'value' => $qsCfg['label']  ?? '—', 'caption' => 'Statut qualification'],
                ['icon' => 'bi-people',      'color' => $relCfg['color'] ?? 'secondary', 'value' => $relCfg['label'] ?? '—', 'caption' => 'Relation'],
                ['icon' => 'bi-search',      'color' => $srcCfg['color'] ?? 'secondary', 'value' => $srcCfg['label'] ?? '—', 'caption' => 'Source'],
                ['icon' => 'bi-graph-up',    'color' => $scColor,                        'value' => $sc ?? '—',               'caption' => 'Score IA'],
            ] : [],
            'toggle'        => $toggle,
            'tabs'          => $tabs,
            'detail_rows'   => $detailRows,
            'stat_cards'    => $statCards,
            'charts'        => $stats ? static::buildCharts($stats) : [],
            'quick_actions' => $quickActions,
        ];
    }

    /**
     * Build the charts array from a pre-computed $stats payload.
     *
     * Returns an array of chart descriptors consumed by <x-crud.chart> via _apercu partial.
     * The series/categories/labels/colors/options/type values are passed as data-crud-chart
     * JSON and rendered by crud-charts.js.
     *
     * Chart layout (3 charts):
     *   1. Donut  — Contacts par statut       (col-md-6)
     *   2. Bar    — Engagement e-mail funnel  (col-12, horizontal distributed)
     *   3. Area   — Contacts sur 12 mois      (col-12)
     */
    private static function buildCharts(array $stats): array
    {
        $charts = [];

        // ── 1. Donut — Contacts par statut ────────────────────────────────────
        $donut = $stats['contacts_donut'] ?? ['series' => [], 'labels' => [], 'colors' => []];
        $charts[] = [
            'id'         => 'company_contacts_donut',
            'title'      => 'Contacts par statut',
            'type'       => 'donut',
            'series'     => $donut['series']  ?? [],
            'categories' => [],
            'labels'     => $donut['labels']  ?? [],
            'colors'     => $donut['colors']  ?? [],
            'options'    => [],
            'height'     => 300,
            'color'      => 'primary',
            'showTotal'  => true,
            'hollowSize' => '60%',
            'empty'      => 'Aucun contact',
            'emptyIcon'  => 'bi-people',
        ];

        // ── 3. Bar (horizontal distributed) — Engagement e-mail funnel ────────
        $funnel = $stats['funnel'] ?? ['series' => [], 'labels' => []];
        $charts[] = [
            'id'         => 'company_funnel',
            'title'      => 'Engagement e-mail',
            'type'       => 'bar',
            // bar/line/area series shape: [{name, data}]
            'series'     => [['name' => 'Contacts', 'data' => $funnel['series'] ?? []]],
            'categories' => $funnel['labels'] ?? [],
            'labels'     => [],
            'colors'     => ['#009EF7', '#50CD89', '#FFC700', '#7239EA', '#F1416C'],
            'options'    => [
                'plotOptions' => [
                    'bar' => [
                        'horizontal'   => true,
                        'distributed'  => true,
                        'borderRadius' => 4,
                    ],
                ],
                'dataLabels' => [
                    'enabled'     => true,
                    'textAnchor'  => 'start',
                    'offsetX'     => 0,
                ],
                'legend' => ['show' => false],
            ],
            'height'     => 320,
            'color'      => 'primary',
            'showTotal'  => false,
            'hollowSize' => '60%',
            'empty'      => 'Disponible après le lancement des campagnes',
            'emptyIcon'  => 'bi-bar-chart',
        ];

        // ── 4. Area — Contacts sur 12 mois ────────────────────────────────────
        $ot = $stats['contacts_over_time'] ?? ['series' => [], 'labels' => []];
        $charts[] = [
            'id'         => 'company_contacts_ot',
            'title'      => 'Contacts (12 mois)',
            'type'       => 'area',
            // area series shape: [{name, data}]
            'series'     => [['name' => 'Contacts', 'data' => $ot['series'] ?? []]],
            'categories' => $ot['labels'] ?? [],
            'labels'     => [],
            'colors'     => [],
            'options'    => [
                'stroke' => ['curve' => 'smooth'],
                'fill'   => ['type'  => 'gradient'],
            ],
            'height'     => 300,
            'color'      => 'primary',
            'showTotal'  => false,
            'hollowSize' => '60%',
            'empty'      => 'Aucune donnée',
            'emptyIcon'  => 'bi-graph-up',
        ];

        return $charts;
    }
}
