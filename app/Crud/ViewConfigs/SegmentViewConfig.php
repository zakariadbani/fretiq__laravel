<?php

namespace App\Crud\ViewConfigs;

use App\Models\Segment;

/**
 * ViewConfig descriptor for the Segment detail/edit pages.
 *
 * Consumed by:
 *   - SegmentController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * No boolean toggle — segments have no is_active field.
 * No charts — segments have no time-series data.
 * One stat card: contacts count (passed via $stats['contacts_count']).
 */
class SegmentViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Segment|null  $model
     * @param  array|null    $stats  Optional pre-computed stats; expects ['contacts_count' => int].
     *                               When null, stat_cards show empty-state hints.
     */
    public static function make(?Segment $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Scope badge ───────────────────────────────────────────────────────
        $scopeCfg   = $hasId ? config('global.data.segment_scopes.' . $model->scope, []) : [];
        $scopeLabel = $scopeCfg['label'] ?? null;
        $scopeColor = $scopeCfg['color'] ?? 'secondary';

        // ── Contacts count ────────────────────────────────────────────────────
        $contactsCount = $stats['contacts_count'] ?? null;

        // ── Subtitle items ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            if ($scopeLabel) {
                $subtitle[] = ['icon' => 'bi-funnel', 'text' => $scopeLabel];
            }
            if ($contactsCount !== null) {
                $subtitle[] = ['icon' => 'bi-people', 'text' => $contactsCount . ' contact(s)'];
            }
        }

        // ── Status-bar toggle — null (no boolean field on Segment) ────────────
        $toggle = null;

        // ── Tabs ──────────────────────────────────────────────────────────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',     'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-funnel',   'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        // NOTE: « Dernière construction » (last_built_at) row removed 2026-06-10.
        // Column exists but is unused until the cache-stamp feature is wired (see TODOS.md D10).
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',    'value' => $model->name,  'type' => 'text'],
                ['label' => 'Portée', 'value' => $model->scope, 'type' => 'enum', 'configKey' => 'segment_scopes'],
                ['label' => 'Créé le', 'value' => $model->created_at, 'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $statCards = [
            [
                'icon'  => 'bi-people',
                'color' => 'primary',
                'label' => 'Destinataires éligibles',
                'value' => $contactsCount,
                'hint'  => $contactsCount === null
                    ? 'Calcul en attente'
                    : 'Nombre réel après filtres, suppressions et règles d\'envoi',
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
                'href'       => route('admin.campaigns.create', ['segment_id' => $model->id]),
            ];
        }

        return [
            'route_base'    => 'admin.segments',
            'route_base_id' => 'segment',
            'permission'    => 'segments',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'primary',
            ],
            'badges'        => $scopeLabel ? [['label' => $scopeLabel, 'color' => $scopeColor]] : [],
            'subtitle'      => $subtitle,
            'tiles'         => $hasId ? [
                ['icon' => 'bi-funnel', 'color' => $scopeColor, 'value' => $scopeLabel ?? '—', 'caption' => 'Portée'],
                ['icon' => 'bi-people', 'color' => 'primary',   'value' => $contactsCount ?? '—', 'caption' => 'Destinataires éligibles'],
                ['icon' => 'bi-calendar3', 'color' => 'secondary', 'value' => $model->created_at ? $model->created_at->format('d/m/Y') : '—', 'caption' => 'Créé le'],
            ] : [],
            'toggle'        => $toggle,
            'tabs'          => $tabs,
            'detail_rows'   => $detailRows,
            'stat_cards'    => $statCards,
            'charts'        => [],
            'quick_actions' => $quickActions,
        ];
    }
}
