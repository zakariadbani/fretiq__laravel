<?php

namespace App\Crud\ViewConfigs;

use App\Models\Campaign;
use App\Models\CampaignRun;

/**
 * ViewConfig descriptor for the Campaign detail/edit pages.
 *
 * Consumed by:
 *   - CampaignController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * Tabs: Aperçu (view-only default) | Général (edit cross-link) |
 *       Historique (exécutions, view-only) | Destinataires (tous les envois, view-only).
 */
class CampaignViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Campaign|null  $model
     * @param  array|null     $stats            Optional pre-computed stats from CampaignController::campaignStats().
     * @param  int|null       $recipientsTotal       Distinct contacts across all runs (null = unknown, badge omitted).
     * @param  int|null       $currentAudienceTotal  Live segment audience count (null = unknown, badge omitted).
     * @param  int|null       $executedRunsTotal     Executed history count (null = derive from loaded runs).
     */
    public static function make(?Campaign $model, ?array $stats = null, ?int $recipientsTotal = null, ?int $currentAudienceTotal = null, ?int $executedRunsTotal = null): array
    {
        $hasId = $model && $model->id;

        // ── Schedule type badge ───────────────────────────────────────────────
        $typeCfg   = $hasId ? config('global.data.schedule_types.' . $model->schedule_type, []) : [];
        $typeLabel = $typeCfg['label'] ?? null;
        $typeColor = $typeCfg['color'] ?? 'secondary';
        $timezone  = $hasId ? $model->scheduleTimezone() : null;

        // ── Hero badges (schedule type) ──────────────────────────────────────
        $badges = [];
        if ($hasId && $typeLabel) {
            $badges[] = ['label' => $typeLabel, 'color' => $typeColor];
        }
        if ($hasId && $model->schedule_type === 'sequence') {
            $badges[] = [
                'label' => $model->sequence_enrollment_mode === 'paced' ? 'Inscription progressive' : 'Inscription immédiate',
                'color' => $model->sequence_enrollment_mode === 'paced' ? 'info' : 'secondary',
            ];
        }
        if ($hasId && $model->isOverdue()) {
            $badges[] = ['label' => 'En retard', 'color' => 'danger'];
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
            $nextRun = $model->scheduledAtLocal();
            if ($model->effectiveScheduledAt() && $nextRun) {
                $subtitle[] = ['icon' => 'bi-clock', 'text' => 'Prochain envoi : ' . $nextRun->format('d/m/Y H:i') . ' ' . $timezone];
            }
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $tiles = [];
        if ($hasId) {
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
                'value'   => $stats ? CampaignRun::rateLabel($stats['open_rate'] ?? null) : '—',
                'caption' => "Taux d'ouverture",
            ];
        }

        // ── Status-bar toggle ─────────────────────────────────────────────────
        $toggle = null;
        if ($hasId) {
            if ($model->schedule_type === 'sequence') {
                $toggle = [
                    'field'       => 'sequence_auto_enroll_enabled',
                    'route'       => route('admin.campaigns.sequenceAutoEnroll', $model->id),
                    'permission'  => 'send campaigns',
                    'title'       => 'Inscription automatique',
                    'description' => $model->sequence_enrollment_mode === 'paced'
                        ? 'Inscrit un nombre limité de sociétés chaque jour ouvré. Les parcours commencés continuent après arrêt.'
                        : 'Inscrit les nouveaux contacts du segment. Les parcours déjà commencés continuent après arrêt.',
                    'success'     => 'Inscription automatique mise à jour',
                    'error'       => 'Échec de la mise à jour',
                    'icon'        => 'bi-person-plus-fill',
                ];
            } else {
                $toggle = [
                    'field'       => 'is_active',
                    'route'       => route('admin.campaigns.executeSwitch', $model->id),
                    'permission'  => $model->schedule_type === 'paced' && ! $model->is_active
                        ? 'send campaigns'
                        : 'edit campaigns',
                    'title'       => 'Campagne active',
                    'description' => 'Décochez pour mettre en pause (le planificateur ignore la campagne).',
                    'success'     => 'Campagne mise à jour',
                    'error'       => 'Échec de la mise à jour',
                    'icon'        => 'bi-check-circle-fill',
                ];
            }
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        // $runsCount is null when runs relation is not loaded (edit/create) — badge omitted.
        $runsCount = $executedRunsTotal ?? ($model && $model->relationLoaded('runs')
            ? $model->runs->filter(fn (CampaignRun $run) => $run->isExecuted())->count()
            : null);

        $tabs = [
            ['key' => 'apercu',        'label' => 'Aperçu',        'icon' => 'bi-grid',          'mode' => 'view'],
            ['key' => 'general',       'label' => 'Général',       'icon' => 'bi-megaphone',     'mode' => 'edit'],
            ['key' => 'historique',    'label' => 'Historique',    'icon' => 'bi-clock-history', 'mode' => 'view', 'count' => $runsCount],
            ...($hasId && $model->schedule_type === 'sequence' && $model->sequence_enrollment_mode === 'paced'
                ? [['key' => 'vagues', 'label' => 'Vagues', 'icon' => 'bi-layers', 'mode' => 'view']]
                : []),
            ['key' => 'audience_actuelle', 'label' => 'Audience actuelle', 'icon' => 'bi-people', 'mode' => 'view', 'count' => $currentAudienceTotal],
            ['key' => 'destinataires', 'label' => 'Destinataires', 'icon' => 'bi-envelope',      'mode' => 'view', 'count' => $recipientsTotal],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',         'value' => $model->name,            'type' => 'text'],
                ['label' => 'Type',        'value' => $model->schedule_type,   'type' => 'enum', 'configKey' => 'schedule_types'],
                ['label' => 'Segment',     'value' => $model->segment?->name,  'type' => 'text'],
                ['label' => 'Modèle',      'value' => $model->template?->name, 'type' => 'text'],
                ['label' => 'Expéditeur',  'value' => $model->senderIdentity?->name, 'type' => 'text'],
            ];

            if ($model->scheduled_at) {
                $detailRows[] = ['label' => 'Planifié le', 'value' => $model->scheduled_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') . ' ' . $timezone, 'type' => 'text'];
            }
            if ($model->next_run_at) {
                $detailRows[] = ['label' => $model->schedule_type === 'sequence' ? 'Prochain lot' : 'Prochain envoi', 'value' => $model->next_run_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') . ' ' . $timezone, 'type' => 'text'];
            }
            if ($model->schedule_type === 'sequence') {
                $detailRows[] = ['label' => 'Mode d’inscription', 'value' => $model->sequence_enrollment_mode === 'paced' ? 'Progressif' : 'Tous immédiatement', 'type' => 'text'];
            }
            if ($model->schedule_type === 'paced' || ($model->schedule_type === 'sequence' && $model->sequence_enrollment_mode === 'paced')) {
                $detailRows[] = ['label' => 'Sociétés par jour', 'value' => $model->pacedDailyCompanyLimit(), 'type' => 'text'];
                $detailRows[] = ['label' => 'Jours d’envoi', 'value' => 'Du lundi au vendredi', 'type' => 'text'];
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
