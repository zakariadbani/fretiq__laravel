<?php

namespace App\Crud\ViewConfigs;

use App\Models\SenderIdentity;

/**
 * ViewConfig descriptor for the SenderIdentity detail/edit pages.
 *
 * Consumed by:
 *   - SenderIdentityController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * Toggle semantics:
 *   - is_active  → hero status-bar (single active toggle)
 *   - is_default → hero tile only (badge "Par défaut"); stays toggleable from the listing switch column.
 *     The custom executeSwitch in SenderIdentityController enforces the single-default rule.
 */
class SenderIdentityViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  SenderIdentity|null  $model
     * @param  array|null           $stats  Optional pre-computed stats array; reserved for future use.
     */
    public static function make(?SenderIdentity $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            $subtitle[] = ['icon' => 'bi-envelope', 'text' => $model->email];
            if ($model->reply_to) {
                $subtitle[] = ['icon' => 'bi-reply', 'text' => 'Reply-To : ' . $model->reply_to];
            }
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        // Tile 1: Par défaut (is_default). Exclusive — toggled from the listing.
        // Tile 2: Actif (is_active). Also carried in the status-bar toggle below.
        $tiles = [];
        if ($hasId) {
            $defaultColor = $model->is_default ? 'success' : 'secondary';
            $defaultLabel = $model->is_default ? 'Par défaut' : 'Non défaut';

            $activeColor = $model->is_active ? 'success' : 'danger';
            $activeLabel = $model->is_active ? 'Actif' : 'Inactif';

            $tiles = [
                ['icon' => 'bi-star-fill',   'color' => $defaultColor, 'value' => $defaultLabel, 'caption' => 'Identité par défaut'],
                ['icon' => 'bi-toggle-on',   'color' => $activeColor,  'value' => $activeLabel,  'caption' => 'Statut'],
            ];
        }

        // ── Status-bar toggle (is_active only — null when no id — omit on create) ──
        $toggle = null;
        if ($hasId) {
            $toggle = [
                'field'       => 'is_active',
                'route'       => route('admin.sender_identities.executeSwitch', $model->id),
                'permission'  => 'edit sender_identities',
                'title'       => 'Identité active',
                'description' => 'Inclure cette identité dans les campagnes.',
                'success'     => 'Identité mise à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        $tabs = [
            ['key' => 'sender_apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',        'mode' => 'view'],
            ['key' => 'sender_general', 'label' => 'Général', 'icon' => 'bi-person-badge', 'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',          'value' => $model->name,       'type' => 'text'],
                ['label' => 'Email',        'value' => $model->email,      'type' => 'text'],
                ['label' => 'Répondre à',   'value' => $model->reply_to,   'type' => 'text'],
                ['label' => 'Par défaut',   'value' => $model->is_default, 'type' => 'boolean'],
                ['label' => 'Actif',        'value' => $model->is_active,  'type' => 'boolean'],
                ['label' => 'Créé le',      'value' => $model->created_at, 'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        // Cheap count: campaigns using this identity (hasMany via sender_identity_id).
        $campaignsCount = null;
        if ($hasId) {
            try {
                $campaignsCount = \App\Models\Campaign::where('sender_identity_id', $model->id)->count();
            } catch (\Throwable $e) {
                $campaignsCount = null;
            }
        }

        $statCards = [
            [
                'icon'  => 'bi-envelope-paper',
                'color' => 'primary',
                'label' => 'Campagnes utilisant cette identité',
                'value' => $campaignsCount,
                'hint'  => $campaignsCount === null ? 'Disponible après le lancement des campagnes' : null,
            ],
        ];

        return [
            'route_base'    => 'admin.sender_identities',
            'route_base_id' => 'sender',
            'permission'    => 'sender_identities',
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
            'quick_actions' => [],
        ];
    }
}
