<?php

namespace App\Crud\ViewConfigs;

use App\Models\Contact;
use Illuminate\Support\Facades\Route;

/**
 * ViewConfig descriptor for the Contact detail/edit pages.
 *
 * Consumed by:
 *   - ContactController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 */
class ContactViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Contact|null  $model
     * @param  array|null    $stats  Optional pre-computed stats from ContactController::contactStats().
     *                               When provided, real KPI values are injected into stat_cards and charts.
     *                               When null, stat_cards show honest empty-state hints.
     */
    public static function make(?Contact $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Status badge ──────────────────────────────────────────────────────
        $statusCfg   = $hasId ? config('global.data.contact_statuses.' . $model->status, []) : [];
        $statusLabel = $statusCfg['label'] ?? null;
        $statusColor = $statusCfg['color'] ?? 'secondary';

        // ── Badges (status + suppressed) ──────────────────────────────────────
        $badges = [];
        if ($hasId && $statusLabel) {
            $badges[] = ['label' => $statusLabel, 'color' => $statusColor];
        }
        if ($hasId && ($stats['suppressed'] ?? false)) {
            $badges[] = ['label' => 'Supprimé', 'color' => 'danger'];
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $lbCfg  = $hasId ? config('global.data.contact_legal_bases.' . $model->legal_basis, []) : [];
        $ekCfg  = $hasId ? config('global.data.contact_email_kinds.' . $model->email_kind, []) : [];
        $srcCfg = $hasId ? config('global.data.contact_sources.' . $model->source, []) : [];

        // ── Subtitle items ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            if ($model->email) {
                $subtitle[] = ['icon' => 'bi-envelope', 'text' => $model->email, 'href' => 'mailto:' . $model->email];
            }
            if ($model->position) {
                $subtitle[] = ['icon' => 'bi-briefcase', 'text' => $model->position];
            }
            if ($model->company && $model->company->name) {
                $companyHref = Route::has('admin.companies.view')
                    ? route('admin.companies.view', $model->company_id)
                    : null;
                $subtitle[] = ['icon' => 'bi-building', 'text' => $model->company->name, 'href' => $companyHref];
            }
        }

        // ── Status-bar toggle — contacts have no boolean toggle field ─────────
        // Contact model has no is_active or equivalent toggleable boolean.
        $toggle = null;

        // ── Tabs — view + general (edit) only, no out-of-form panes ──────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',     'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-person',   'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',          'value' => $model->name,     'type' => 'text'],
                ['label' => 'Email',         'value' => $model->email,    'type' => 'email'],
                ['label' => 'Poste',         'value' => $model->position, 'type' => 'text'],
                ['label' => 'Téléphone',     'value' => $model->phone,    'type' => 'text'],
                [
                    'label' => 'Entreprise',
                    'value' => $model->company ? $model->company->name : null,
                    'type'  => 'link',
                    'href'  => ($model->company && Route::has('admin.companies.view'))
                        ? route('admin.companies.view', $model->company_id)
                        : null,
                ],
                ['label' => 'Statut',      'value' => $model->status,     'type' => 'enum', 'configKey' => 'contact_statuses'],
                ['label' => 'Base légale', 'value' => $model->legal_basis, 'type' => 'enum', 'configKey' => 'contact_legal_bases'],
                ['label' => 'Type email',  'value' => $model->email_kind,  'type' => 'enum', 'configKey' => 'contact_email_kinds'],
                ['label' => 'Source',      'value' => $model->source,      'type' => 'enum', 'configKey' => 'contact_sources'],
                ['label' => 'Créé le',     'value' => $model->created_at,  'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $statCards = [
            [
                'icon'  => 'bi-envelope-check',
                'color' => 'primary',
                'label' => 'Emails envoyés',
                'value' => $stats ? $stats['emails_sent'] : null,
                'hint'  => $stats ? null : 'Disponible après le lancement des campagnes',
            ],
            [
                'icon'  => 'bi-eye',
                'color' => 'info',
                'label' => 'Ouvertures',
                'value' => $stats ? $stats['emails_opened'] : null,
                'hint'  => $stats ? null : 'Disponible après le lancement des campagnes',
            ],
            [
                'icon'  => 'bi-cursor',
                'color' => 'success',
                'label' => 'Clics',
                'value' => $stats ? $stats['emails_clicked'] : null,
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

        // ── Charts ────────────────────────────────────────────────────────────
        $charts = $stats ? static::buildCharts($stats) : [];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId && $model->company_id && Route::has('admin.companies.view')) {
            $quickActions[] = [
                'label'      => 'Voir l\'entreprise',
                'icon'       => 'bi-building',
                'color'      => 'light-primary',
                'permission' => 'view companies',
                'href'       => route('admin.companies.view', $model->company_id),
            ];
        }
        if ($hasId && Route::has('admin.demandes.create')) {
            $quickActions[] = [
                'label'      => 'Créer une demande',
                'icon'       => 'bi-plus-circle',
                'color'      => 'light-success',
                'permission' => 'create demandes',
                'href'       => route('admin.demandes.create'),
            ];
        }

        return [
            'route_base'    => 'admin.contacts',
            'route_base_id' => 'contact',
            'permission'    => 'contacts',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'info',
            ],
            'badges'        => $badges,
            'subtitle'      => $subtitle,
            'tiles'         => $hasId ? [
                ['icon' => 'bi-patch-check',    'color' => $statusColor,                    'value' => $statusLabel ?? '—',         'caption' => 'Statut'],
                ['icon' => 'bi-shield-check',   'color' => $lbCfg['color']  ?? 'secondary', 'value' => $lbCfg['label']  ?? '—',    'caption' => 'Base légale'],
                ['icon' => 'bi-envelope-at',    'color' => $ekCfg['color']  ?? 'secondary', 'value' => $ekCfg['label']  ?? '—',    'caption' => 'Type email'],
                ['icon' => 'bi-search',         'color' => $srcCfg['color'] ?? 'secondary', 'value' => $srcCfg['label'] ?? '—',    'caption' => 'Source'],
            ] : [],
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
     * ONE bar funnel chart: Envoyés / Délivrés / Ouverts / Cliqués / Répondus.
     * Mirrors CompanyViewConfig::buildCharts() funnel shape.
     */
    private static function buildCharts(array $stats): array
    {
        $charts = [];

        // ── Bar (horizontal distributed) — Engagement e-mail funnel ──────────
        $funnel = $stats['funnel'] ?? ['series' => [], 'labels' => []];
        $charts[] = [
            'id'         => 'contact_funnel',
            'title'      => 'Engagement e-mail',
            'type'       => 'bar',
            'series'     => [['name' => 'Emails', 'data' => $funnel['series'] ?? []]],
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
                    'enabled'    => true,
                    'textAnchor' => 'start',
                    'offsetX'    => 0,
                ],
                'legend' => ['show' => false],
            ],
            'height'     => 300,
            'color'      => 'primary',
            'showTotal'  => false,
            'hollowSize' => '60%',
            'empty'      => 'Disponible après le lancement des campagnes',
            'emptyIcon'  => 'bi-bar-chart',
        ];

        return $charts;
    }
}
