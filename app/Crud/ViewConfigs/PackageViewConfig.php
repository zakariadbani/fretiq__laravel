<?php

namespace App\Crud\ViewConfigs;

use App\Models\Package;
use Illuminate\Support\Facades\Route;

/**
 * ViewConfig descriptor for the Package detail/edit pages.
 *
 * Tabs: Apercu (view) + General (edit) only.
 * No Enrichissement tab — packages are a simple config entity.
 * No domain tabs (no hasMany relationships shown on view/edit).
 */
class PackageViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Package|null  $model
     * @param  array|null    $stats  Pre-computed stats from PackageController::packageStats().
     */
    public static function make(?Package $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Status-bar toggle (null when no id — omit on create) ─────────────
        $toggle = null;
        if ($hasId) {
            $toggle = [
                'field'       => 'is_active',
                'route'       => route('admin.packages.executeSwitch', $model->id),
                'permission'  => 'manage packages',
                'title'       => 'Pack actif',
                'description' => 'Inclure ce pack dans la liste des packs disponibles.',
                'success'     => 'Mis à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        // Minimal: Apercu (view) + General (edit). No Enrichissement for a config entity.
        $tabs = [
            ['key' => 'apercu',   'label' => 'Aperçu',   'icon' => 'bi-grid',    'mode' => 'view'],
            ['key' => 'general',  'label' => 'Général',  'icon' => 'bi-sliders', 'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $creditsLabel = $model->daily_credits === null ? 'Illimité' : (string) $model->daily_credits;
            $priceLine    = $model->price_monthly !== null
                ? number_format((float) $model->price_monthly, 2, ',', ' ') . ' € / mois'
                : null;

            $detailRows = [
                ['label' => 'Actif',             'value' => $model->is_active,   'type' => 'boolean'],
                ['label' => 'Nom',               'value' => $model->name,         'type' => 'text'],
                ['label' => 'Crédits / jour',    'value' => $creditsLabel,        'type' => 'text'],
                ['label' => 'Prix / mois',       'value' => $priceLine,           'type' => 'text'],
                ['label' => 'Ordre d\'affichage','value' => $model->sort_order,   'type' => 'text'],
                ['label' => 'Créé le',           'value' => $model->created_at,   'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $statCards = [
            [
                'icon'  => 'bi-arrow-repeat',
                'color' => 'primary',
                'label' => 'Assignations',
                'value' => $stats ? ($stats['assignments_count'] ?? null) : null,
                'hint'  => null,
            ],
            [
                'icon'  => 'bi-play-circle',
                'color' => 'info',
                'label' => 'Runs autorisés',
                'value' => $stats ? ($stats['runs_count'] ?? null) : null,
                'hint'  => null,
            ],
        ];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId) {
            if (Route::has('admin.packages.assign')) {
                $quickActions[] = [
                    'label'      => 'Assigner ce pack',
                    'icon'       => 'bi-check2-square',
                    'color'      => 'light-primary',
                    'permission' => 'manage packages',
                    'href'       => '#',   // The view page will render an inline POST form instead
                ];
            }
        }

        return [
            // -- Identity --
            'route_base'    => 'admin.packages',
            'route_base_id' => 'package',
            'permission'    => 'packages',

            // -- Hero --
            'title'    => $hasId ? $model->name : '',
            'avatar'   => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'primary',
            ],
            'badges'   => $hasId ? [
                $model->daily_credits === null
                    ? ['label' => 'Illimité', 'color' => 'success']
                    : ['label' => $model->daily_credits . ' crédits/jour', 'color' => 'primary'],
            ] : [],
            'subtitle' => $hasId && $model->price_monthly !== null ? [
                ['icon' => 'bi-tag', 'text' => number_format((float) $model->price_monthly, 2, ',', ' ') . ' € / mois'],
            ] : [],
            'tiles'    => [],
            'toggle'   => $toggle,

            // -- Tabbar --
            'tabs' => $tabs,

            // -- Apercu --
            'detail_rows'   => $detailRows,
            'stat_cards'    => $statCards,
            'charts'        => [],    // No charts for a config-type entity
            'quick_actions' => [],    // Quick actions rendered inline in the view template
        ];
    }
}
