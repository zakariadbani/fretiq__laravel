<?php

namespace App\Crud\ViewConfigs;

use App\Models\Campaign;

/**
 * ViewConfig descriptor for the Campaign detail/edit pages.
 *
 * Consumed by:
 *   - CampaignController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * Note: The view page is a report page — hero + tabbar wrap with the apercu pane
 * containing details + stat cards + funnel chart, followed by the preserved runs table
 * block and recipient drill-down inside the apercu pane below _apercu.
 */
class CampaignViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Campaign|null  $model
     * @param  array|null     $stats  Optional pre-computed stats array from CampaignController::campaignStats().
     *                                When provided, real KPI values and chart series are injected.
     */
    public static function make(?Campaign $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Status badge ──────────────────────────────────────────────────────
        $statusCfg   = $hasId ? config('global.data.campaign_statuses.' . $model->status, []) : [];
        $statusLabel = $statusCfg['label'] ?? 'Brouillon';
        $statusColor = $statusCfg['color'] ?? 'secondary';

        // ── Schedule type badge ───────────────────────────────────────────────
        $typeCfg   = $hasId ? config('global.data.schedule_types.' . $model->schedule_type, []) : [];
        $typeLabel = $typeCfg['label'] ?? null;
        $typeColor = $typeCfg['color'] ?? 'secondary';

        // ── Hero badges (status + schedule type) ──────────────────────────────
        $badges = [];
        if ($hasId) {
            $badges[] = ['label' => $statusLabel, 'color' => $statusColor];
            if ($typeLabel) {
                $badges[] = ['label' => $typeLabel, 'color' => $typeColor];
            }
        }

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            if ($model->segment) {
                $subtitle[] = ['icon' => 'bi-people', 'text' => $model->segment->name];
            }
            if ($typeLabel) {
                $subtitle[] = ['icon' => 'bi-calendar2', 'text' => $typeLabel];
            }
            // Prochaine exécution (scheduled_at or next_run_at)
            $nextRun = $model->next_run_at ?? $model->scheduled_at;
            if ($nextRun) {
                $subtitle[] = ['icon' => 'bi-clock', 'text' => 'Prochain envoi : ' . $nextRun->format('d/m/Y H:i')];
            }
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $tiles = [];
        if ($hasId) {
            // Statut
            $tiles[] = [
                'icon'    => 'bi-patch-check',
                'color'   => $statusColor,
                'value'   => $statusLabel,
                'caption' => 'Statut',
            ];
            // Type
            $tiles[] = [
                'icon'    => 'bi-calendar2',
                'color'   => $typeColor,
                'value'   => $typeLabel ?? '—',
                'caption' => 'Type',
            ];
            // Envoyés (from stats or run)
            $tiles[] = [
                'icon'    => 'bi-send',
                'color'   => 'primary',
                'value'   => $stats ? number_format((int) ($stats['total_sent'] ?? 0)) : '—',
                'caption' => 'Envoyés',
            ];
            // Taux d'ouverture
            $tiles[] = [
                'icon'    => 'bi-envelope-open',
                'color'   => 'success',
                'value'   => $stats ? ($stats['open_rate'] ?? '0') . '%' : '—',
                'caption' => "Taux d'ouverture",
            ];
        }

        // ── Status-bar toggle ─────────────────────────────────────────────────
        // Campaign has no boolean is_active toggle — always null.
        $toggle = null;

        // ── Tabs ──────────────────────────────────────────────────────────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',     'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-megaphone','mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',         'value' => $model->name,            'type' => 'text'],
                ['label' => 'Statut',      'value' => $model->status,          'type' => 'enum', 'configKey' => 'campaign_statuses'],
                ['label' => 'Type',        'value' => $model->schedule_type,   'type' => 'enum', 'configKey' => 'schedule_types'],
                ['label' => 'Segment',     'value' => $model->segment?->name,  'type' => 'text'],
                ['label' => 'Modèle',      'value' => $model->template?->name, 'type' => 'text'],
                ['label' => 'Expéditeur',  'value' => $model->senderIdentity?->name, 'type' => 'text'],
            ];

            if ($model->scheduled_at) {
                $detailRows[] = ['label' => 'Planifié le', 'value' => $model->scheduled_at, 'type' => 'date'];
            }
            if ($model->next_run_at) {
                $detailRows[] = ['label' => 'Prochain envoi', 'value' => $model->next_run_at, 'type' => 'date'];
            }
            if ($model->timezone) {
                $detailRows[] = ['label' => 'Fuseau horaire', 'value' => $model->timezone, 'type' => 'text'];
            }

            $detailRows[] = ['label' => 'Créé le', 'value' => $model->created_at, 'type' => 'date'];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $statCards = [
            [
                'icon'  => 'bi-send',
                'color' => 'primary',
                'label' => 'Envoyés',
                'value' => $stats ? ($stats['total_sent'] ?? 0) : null,
                'hint'  => $stats ? null : 'Disponible après le lancement de la campagne',
            ],
            [
                'icon'  => 'bi-envelope-open',
                'color' => 'success',
                'label' => 'Ouvertures',
                'value' => $stats ? ($stats['total_opened'] ?? 0) : null,
                'hint'  => $stats ? null : 'Disponible après le lancement de la campagne',
            ],
            [
                'icon'  => 'bi-hand-index',
                'color' => 'info',
                'label' => 'Clics',
                'value' => $stats ? ($stats['total_clicked'] ?? 0) : null,
                'hint'  => $stats ? null : 'Disponible après le lancement de la campagne',
            ],
            [
                'icon'  => 'bi-inbox',
                'color' => 'warning',
                'label' => 'Demandes',
                'value' => $stats ? ($stats['total_conversions'] ?? 0) : null,
                'hint'  => $stats ? null : 'Disponible après le lancement de la campagne',
            ],
        ];

        // ── Charts ────────────────────────────────────────────────────────────
        $charts = $stats ? static::buildCharts($stats) : [];

        // ── Quick actions ─────────────────────────────────────────────────────
        // Real campaign actions (Planifier / Envoyer) live in _header-actions — not here.
        $quickActions = [];

        return [
            'route_base'    => 'admin.campaigns',
            'route_base_id' => 'campaign',
            'permission'    => 'campaigns',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'primary',
            ],
            'badges'        => $badges,
            'subtitle'      => $subtitle,
            'tiles'         => $tiles,
            'toggle'        => $toggle,
            'tabs'          => $tabs,
            'detail_rows'   => $detailRows,
            'stat_cards'    => $statCards,
            'charts'        => $charts,
            'quick_actions' => $quickActions,
        ];
    }

    /**
     * Build the charts array from a pre-computed $stats payload.
     *
     * Charts:
     *   1. Bar (horizontal distributed) — Engagement funnel (col-md-6)
     *   2. Area — Ouvertures (12 dernières exécutions) (col-md-6)
     */
    private static function buildCharts(array $stats): array
    {
        $charts = [];

        // ── 1. Bar (horizontal distributed) — Engagement funnel ──────────────
        $funnelLabels = ['Envoyés', 'Délivrés', 'Ouverts', 'Cliqués', 'Répondus'];
        $funnelSeries = [
            (int) ($stats['total_sent']      ?? 0),
            (int) ($stats['total_delivered'] ?? 0),
            (int) ($stats['total_opened']    ?? 0),
            (int) ($stats['total_clicked']   ?? 0),
            (int) ($stats['total_replied']   ?? 0),
        ];

        $charts[] = [
            'id'         => 'campaign_funnel',
            'title'      => 'Entonnoir d\'engagement',
            'type'       => 'bar',
            'series'     => [['name' => 'Contacts', 'data' => $funnelSeries]],
            'categories' => $funnelLabels,
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
                    'enabled'    => true,
                    'textAnchor' => 'start',
                    'offsetX'    => 0,
                ],
                'legend' => ['show' => false],
            ],
            'height'     => 280,
            'color'      => 'primary',
            'showTotal'  => false,
            'hollowSize' => '60%',
            'empty'      => 'Disponible après le lancement de la campagne',
            'emptyIcon'  => 'bi-bar-chart',
        ];

        // ── 2. Area — Ouvertures sur les runs récents ─────────────────────────
        $ot = $stats['opens_over_time'] ?? ['series' => [], 'labels' => []];
        $charts[] = [
            'id'         => 'campaign_opens_ot',
            'title'      => 'Ouvertures par exécution',
            'type'       => 'area',
            'series'     => [['name' => 'Ouvertures', 'data' => $ot['series'] ?? []]],
            'categories' => $ot['labels'] ?? [],
            'labels'     => [],
            'colors'     => [],
            'options'    => [
                'stroke' => ['curve' => 'smooth'],
                'fill'   => ['type'  => 'gradient'],
            ],
            'height'     => 280,
            'color'      => 'primary',
            'showTotal'  => false,
            'hollowSize' => '60%',
            'empty'      => 'Disponible après plusieurs exécutions',
            'emptyIcon'  => 'bi-graph-up',
        ];

        return $charts;
    }
}
