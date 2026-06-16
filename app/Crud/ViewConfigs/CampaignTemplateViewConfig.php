<?php

namespace App\Crud\ViewConfigs;

use App\Models\CampaignTemplate;

/**
 * ViewConfig descriptor for the CampaignTemplate detail/edit pages.
 *
 * Consumed by:
 *   - CampaignTemplateController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * Note on the apercu tab: the generic _apercu partial renders detail rows + stat cards.
 * The email HTML preview iframe is rendered BELOW _apercu inside the template_apercu pane
 * in view.blade.php — it is NOT a detail row (HTML body is too large for the detail table).
 */
class CampaignTemplateViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  CampaignTemplate|null  $model
     * @param  array|null             $stats  Optional pre-computed stats (reserved for future use).
     */
    public static function make(?CampaignTemplate $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Subtitle items ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId && $model->subject) {
            $subtitle[] = [
                'icon' => 'bi-envelope',
                'text' => \Str::limit($model->subject, 60),
            ];
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        // Show two tiles: campaigns count + preview_text presence
        $campaignsCount = null;
        if ($hasId) {
            // Use the already-loaded campaigns relation count if available,
            // otherwise do a cheap count query.
            $campaignsCount = isset($model->campaigns_count)
                ? $model->campaigns_count
                : $model->campaigns()->count();
        }

        $tiles = [];
        if ($hasId) {
            $tiles[] = [
                'icon'    => 'bi-collection',
                'color'   => $campaignsCount > 0 ? 'primary' : 'secondary',
                'value'   => $campaignsCount ?? 0,
                'caption' => 'Campagnes utilisant ce modèle',
            ];
            $tiles[] = [
                'icon'    => 'bi-code-slash',
                'color'   => $model->html_content ? 'success' : 'warning',
                'value'   => $model->html_content ? 'Oui' : 'Non',
                'caption' => 'Contenu HTML défini',
            ];
        }

        // ── Status-bar toggle ─────────────────────────────────────────────────
        // CampaignTemplate has no boolean toggle field — always null.
        $toggle = null;

        // ── Tabs ──────────────────────────────────────────────────────────────
        // Two tabs: Aperçu (view) + Général (edit). No out-of-form panes.
        $tabs = [
            ['key' => 'apercu',      'label' => 'Aperçu',      'icon' => 'bi-grid',      'mode' => 'view'],
            ['key' => 'general',     'label' => 'Général',     'icon' => 'bi-envelope',  'mode' => 'edit'],
            ['key' => 'traductions', 'label' => 'Traductions', 'icon' => 'bi-translate', 'mode' => 'edit', 'out_of_form' => true],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        // HTML body is intentionally excluded — it is rendered as an iframe preview below _apercu.
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',                  'value' => $model->name,         'type' => 'text'],
                ['label' => 'Sujet',                'value' => $model->subject,      'type' => 'text'],
                ['label' => 'Texte de prévisualisation', 'value' => $model->preview_text, 'type' => 'text'],
                ['label' => 'Créé le',              'value' => $model->created_at,   'type' => 'date'],
                ['label' => 'Modifié le',           'value' => $model->updated_at,   'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        $statCards = [
            [
                'icon'  => 'bi-collection',
                'color' => 'primary',
                'label' => 'Campagnes utilisant ce modèle',
                'value' => $hasId ? $campaignsCount : null,
                'hint'  => $hasId ? null : null,
            ],
            [
                'icon'  => 'bi-send',
                'color' => 'info',
                'label' => 'Emails envoyés via ce modèle',
                'value' => null,
                'hint'  => 'Disponible après le lancement des campagnes',
            ],
        ];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId && \Illuminate\Support\Facades\Route::has('admin.campaigns.create')) {
            $quickActions[] = [
                'label'      => 'Créer une campagne',
                'icon'       => 'bi-rocket',
                'color'      => 'light-primary',
                'permission' => 'create campaigns',
                'href'       => route('admin.campaigns.create'),
            ];
        }

        return [
            'route_base'    => 'admin.campaign_templates',
            'route_base_id' => 'template',
            'permission'    => 'campaign_templates',
            'title'         => $hasId ? $model->name : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->name : '',
                'color' => 'info',
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
