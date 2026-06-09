<?php

namespace App\Crud\ViewConfigs;

use App\Models\ProspectCriteria;

/**
 * ViewConfig descriptor for the ProspectCriteria detail/edit pages.
 *
 * Consumed by:
 *   - ProspectCriteriaController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 */
class ProspectCriteriaViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  ProspectCriteria|null  $model
     * @param  array|null             $stats  Optional pre-computed stats; reserved for future use.
     */
    public static function make(?ProspectCriteria $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            $sectors = is_array($model->sectors) ? $model->sectors : [];
            if (!empty($sectors)) {
                $subtitle[] = ['icon' => 'bi-briefcase', 'text' => implode(', ', array_slice($sectors, 0, 2))];
            }
            $countries = is_array($model->countries) ? $model->countries : [];
            if (!empty($countries)) {
                $subtitle[] = ['icon' => 'bi-geo-alt', 'text' => implode(', ', array_slice($countries, 0, 2))];
            }
            if ($model->daily_limit) {
                $subtitle[] = ['icon' => 'bi-clock', 'text' => $model->daily_limit . ' / jour'];
            }
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $tiles = [];
        if ($hasId) {
            $activeColor = $model->is_active ? 'success' : 'danger';
            $activeLabel = $model->is_active ? 'Actif' : 'Inactif';

            $sectorCount   = is_array($model->sectors)          ? count($model->sectors)          : 0;
            $countryCount  = is_array($model->countries)        ? count($model->countries)        : 0;
            $sizeCount     = is_array($model->company_sizes)    ? count($model->company_sizes)    : 0;

            $tiles = [
                ['icon' => 'bi-toggle-on',  'color' => $activeColor, 'value' => $activeLabel,           'caption' => 'Statut'],
                ['icon' => 'bi-briefcase',  'color' => 'primary',    'value' => $sectorCount,            'caption' => 'Secteurs'],
                ['icon' => 'bi-geo-alt',    'color' => 'info',       'value' => $countryCount,           'caption' => 'Pays'],
                ['icon' => 'bi-clock',      'color' => 'warning',    'value' => ($model->daily_limit ?? '—'), 'caption' => 'Limite/jour'],
            ];
        }

        // ── Status-bar toggle (null when no id — omit on create) ─────────────
        $toggle = null;
        if ($hasId) {
            $toggle = [
                'field'       => 'is_active',
                'route'       => route('admin.prospect_criteria.executeSwitch', $model->id),
                'permission'  => 'edit prospect_criteria',
                'title'       => 'Critère actif',
                'description' => 'Inclure ce critère dans la découverte automatique.',
                'success'     => 'Critère mis à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',   'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-sliders', 'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $sectors   = is_array($model->sectors)          ? $model->sectors          : [];
            $countries = is_array($model->countries)        ? $model->countries        : [];
            $sizes     = is_array($model->company_sizes)    ? $model->company_sizes    : [];
            $positions = is_array($model->target_positions) ? $model->target_positions : [];

            $detailRows = [
                ['label' => 'Nom',              'value' => $model->name,       'type' => 'text'],
                ['label' => 'Actif',            'value' => $model->is_active,  'type' => 'boolean'],
                ['label' => 'Limite / jour',    'value' => $model->daily_limit ? $model->daily_limit . ' contacts/j' : null, 'type' => 'text'],
                ['label' => 'Secteurs',         'value' => !empty($sectors)   ? implode(', ', $sectors)   : null, 'type' => 'tags'],
                ['label' => 'Pays',             'value' => !empty($countries) ? implode(', ', $countries) : null, 'type' => 'tags'],
                ['label' => 'Tailles',          'value' => !empty($sizes)     ? implode(', ', $sizes)     : null, 'type' => 'tags'],
                ['label' => 'Postes cibles',    'value' => !empty($positions) ? implode(', ', $positions) : null, 'type' => 'tags'],
                ['label' => 'Créé le',          'value' => $model->created_at, 'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $discoveredCount = ($hasId && isset($stats['discovered_total']))
            ? $stats['discovered_total']
            : ($hasId ? $model->companies()->count() : null);

        $statCards = [
            [
                'icon'  => 'bi-building',
                'color' => 'primary',
                'label' => 'Entreprises découvertes',
                'value' => $discoveredCount,
                'hint'  => $discoveredCount === null ? 'Données disponibles après la première découverte' : null,
            ],
            [
                'icon'  => 'bi-clock',
                'color' => 'info',
                'label' => 'Limite / jour',
                'value' => $hasId ? ($model->daily_limit ?? 0) : null,
                'hint'  => null,
            ],
        ];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId) {
            $csrfToken = csrf_token();
            $modelId   = (int) $model->id;
            $quickActions[] = [
                'label'      => 'Lancer la découverte',
                'icon'       => 'bi-play-fill',
                'color'      => 'light-success',
                'permission' => 'run discovery',
                'onclick'    => 'launchDiscovery(' . $modelId . ', \'' . e($csrfToken) . '\')',
            ];
        }

        return [
            'route_base'    => 'admin.prospect_criteria',
            'route_base_id' => 'criteria',
            'permission'    => 'prospect_criteria',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'primary',
            ],
            'badges'        => [],
            'subtitle'      => $subtitle,
            'tiles'         => $tiles,
            'toggle'        => $toggle,
            'tabs'          => $tabs,
            'detail_rows'   => $detailRows,
            'stat_cards'    => $statCards,
            'charts'        => [],
            'quick_actions' => $quickActions,
        ];
    }
}
