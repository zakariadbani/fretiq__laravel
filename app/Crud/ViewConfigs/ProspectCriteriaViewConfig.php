<?php

namespace App\Crud\ViewConfigs;

use App\Models\ProspectCriteria;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

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
        $hasId   = $model && $model->id;
        $quotaTz = $stats['quota_tz'] ?? 'Europe/Paris';

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            $countryLabels = config('global.data.company_countries', []);

            $sectors = is_array($model->sectors) ? $model->sectors : [];
            if (!empty($sectors)) {
                $subtitle[] = ['icon' => 'bi-briefcase', 'text' => implode(', ', array_slice($sectors, 0, 2))];
            }
            $countries = is_array($model->countries) ? $model->countries : [];
            if (!empty($countries)) {
                $mapped = array_map(fn($v) => $countryLabels[$v] ?? $v, array_slice($countries, 0, 2));
                $subtitle[] = ['icon' => 'bi-geo-alt', 'text' => implode(', ', $mapped)];
            }
            if ($model->daily_limit) {
                $subtitle[] = ['icon' => 'bi-clock', 'text' => $model->daily_limit . ' requêtes de découverte/j'];
            }
            if ($model->auto_run && $model->run_at_hour !== null) {
                $subtitle[] = ['icon' => 'bi-alarm', 'text' => 'Auto · ' . sprintf('%02d:00', $model->run_at_hour)];
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
                'description' => 'Un critère inactif ne peut pas lancer de découverte.',
                'success'     => 'Critère mis à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        $runsCount = ($hasId && Schema::hasTable('discovery_runs'))
            ? $model->discoveryRuns()->count()
            : null;

        // Hoist discoveredCount here so both the Résultats tab badge and the
        // stat card below share the same value (no duplicate COUNT query).
        $discoveredCount = ($hasId && isset($stats['discovered_total']))
            ? $stats['discovered_total']
            : ($hasId ? $model->companies()->count() : null);

        $tabs = [
            ['key' => 'apercu',     'label' => 'Aperçu',     'icon' => 'bi-grid',           'mode' => 'view'],
            ['key' => 'historique', 'label' => 'Historique', 'icon' => 'bi-clock-history',  'mode' => 'view', 'count' => $runsCount],
            ['key' => 'resultats',  'label' => 'Résultats',  'icon' => 'bi-building-check',  'mode' => 'view', 'count' => $discoveredCount],
            ['key' => 'general',    'label' => 'Général',    'icon' => 'bi-sliders',         'mode' => 'edit'],
            ['key' => 'automatisation', 'label' => 'Automatisation', 'icon' => 'bi-robot', 'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $countryLabels = config('global.data.company_countries', []);

            $sectors   = is_array($model->sectors)          ? $model->sectors          : [];
            $countries = is_array($model->countries)        ? $model->countries        : [];
            $sizes     = is_array($model->company_sizes)    ? $model->company_sizes    : [];
            $positions = is_array($model->target_positions) ? $model->target_positions : [];

            // Map ISO codes → French labels (passthrough on unknown values).
            $countryDisplay = array_map(fn($v) => $countryLabels[$v] ?? $v, $countries);

            $detailRows = [
                ['label' => 'Nom',              'value' => $model->name,       'type' => 'text'],
                ['label' => 'Actif',            'value' => $model->is_active,  'type' => 'boolean'],
                ['label' => 'Requêtes de découverte / jour', 'value' => $model->daily_limit ? $model->daily_limit . ' requêtes/j' : null, 'type' => 'text'],
                ['label' => 'Découverte automatique', 'value' => ($model->auto_run && $model->run_at_hour !== null)
                    ? 'Quotidienne à ' . sprintf('%02d:00', $model->run_at_hour) . ' (heure ' . $quotaTz . ')'
                    : 'Manuelle', 'type' => 'text'],
                ['label' => 'Contacts max / exécution', 'value' => $model->contact_limit ?? 'Illimité (quota package)', 'type' => 'text'],
                ['label' => 'Score min. d\'enrichissement', 'value' => $model->min_score_enrich ?? ('Hérité (' . Setting::get('decouverte.min_score_enrich', 50) . ')'), 'type' => 'text'],
                ['label' => 'Enrichissement automatique', 'value' => $model->auto_enrich === null ? 'Hérité' : ($model->auto_enrich ? 'Activé' : 'Désactivé'), 'type' => 'text'],
                ['label' => 'Secteurs',         'value' => !empty($sectors)        ? implode(', ', $sectors)        : null, 'type' => 'tags'],
                ['label' => 'Pays',             'value' => !empty($countryDisplay) ? implode(', ', $countryDisplay) : null, 'type' => 'tags'],
                ['label' => 'Tailles',          'value' => !empty($sizes)          ? implode(', ', $sizes)          : null, 'type' => 'tags'],
                ['label' => 'Postes cibles',    'value' => !empty($positions)      ? implode(', ', $positions)      : null, 'type' => 'tags'],
                ['label' => 'Créé le',          'value' => $model->created_at,     'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        // $discoveredCount is hoisted above the $tabs array (shared with tab badge).

        // Discovery run stat — guard with Schema::hasTable so the page renders
        // safely before the discovery_runs migration has been executed.
        $latestRun         = null;
        $runStatusLabel    = 'Jamais lancée';
        $runStatusColor    = 'secondary';
        $runContactsCount  = null;

        if ($hasId && Schema::hasTable('discovery_runs')) {
            $latestRun = $model->latestDiscoveryRun;
            if ($latestRun) {
                $runStatusCfg   = config('global.data.discovery_run_statuses.' . $latestRun->status, []);
                $runStatusLabel = $runStatusCfg['label'] ?? $latestRun->status;
                $runStatusColor = $runStatusCfg['color'] ?? 'secondary';
                $runContactsCount = $latestRun->contacts_count;
            }
        }

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
            [
                'icon'  => 'bi-arrow-repeat',
                'color' => $runStatusColor,
                'label' => 'Dernière découverte',
                'value' => $hasId ? $runStatusLabel : null,
                'hint'  => (!$hasId || $latestRun === null) ? 'Données disponibles après la première découverte' : null,
            ],
            [
                'icon'  => 'bi-person-lines-fill',
                'color' => 'info',
                'label' => 'Contacts (dernière exéc.)',
                'value' => $runContactsCount,
                'hint'  => $runContactsCount === null ? 'Données disponibles après la première découverte' : null,
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
            $duplicateUrl = route('admin.prospect_criteria.duplicate', $modelId);
            $quickActions[] = [
                'label'      => 'Dupliquer',
                'icon'       => 'bi-copy',
                'color'      => 'light-primary',
                'permission' => 'create prospect_criteria',
                'onclick'    => 'submitPostForm(\'' . e($duplicateUrl) . '\', \'' . e($csrfToken) . '\')',
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
