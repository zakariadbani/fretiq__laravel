<?php

namespace App\Crud\ViewConfigs;

use App\Models\User;

/**
 * ViewConfig descriptor for the User detail/edit pages.
 *
 * Consumed by:
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * NOTE: No executeSwitch route exists for users (toggle => null).
 * NOTE: User::getName() returns 'users' (slug) — NEVER use it for route building.
 *       Route base is hard-coded as 'admin.users'.
 */
class UserViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  User|null   $model
     * @param  array|null  $stats  Reserved; not yet used.
     */
    public static function make(?User $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Role badges ───────────────────────────────────────────────────────
        $badges = [];
        if ($hasId && $model->relationLoaded('roles')) {
            foreach ($model->roles as $role) {
                $badges[] = ['label' => ucfirst($role->name), 'color' => 'primary'];
            }
        }

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            $subtitle[] = ['icon' => 'bi-envelope', 'text' => $model->email, 'href' => 'mailto:' . $model->email];
            if ($model->created_at) {
                $subtitle[] = ['icon' => 'bi-calendar3', 'text' => 'Créé le ' . $model->created_at->format('d/m/Y')];
            }
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $tiles = [];
        if ($hasId) {
            $primaryRole = $model->roles->first()?->name;
            $activeColor = ($model->is_active !== false) ? 'success' : 'danger';
            $activeLabel = ($model->is_active !== false) ? 'Actif' : 'Inactif';

            $tiles = [
                ['icon' => 'bi-shield-check', 'color' => 'primary',     'value' => $primaryRole ? ucfirst($primaryRole) : '—', 'caption' => 'Rôle principal'],
                ['icon' => 'bi-toggle-on',    'color' => $activeColor,  'value' => $activeLabel,                                'caption' => 'Compte actif'],
            ];

            // Last login tile — only if field has a value
            if ($model->last_login_at) {
                $tiles[] = ['icon' => 'bi-clock-history', 'color' => 'info', 'value' => $model->last_login_at->format('d/m/Y'), 'caption' => 'Dernière connexion'];
            }
        }

        // ── Status-bar toggle ─────────────────────────────────────────────────
        // No admin.users.executeSwitch route exists — toggle must be null.
        $toggle = null;

        // ── Tabs ──────────────────────────────────────────────────────────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',       'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-person-gear', 'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            // Role names as tags
            $roleNames = $model->roles->pluck('name')->map('ucfirst')->all();

            $detailRows = [
                ['label' => 'Nom',                'value' => $model->name,                                              'type' => 'text'],
                ['label' => 'Email',              'value' => $model->email,                                             'type' => 'email'],
                ['label' => 'Rôles',              'value' => !empty($roleNames) ? implode(', ', $roleNames) : null,     'type' => 'tags'],
                ['label' => 'Compte actif',       'value' => $model->is_active !== false,                              'type' => 'boolean'],
                ['label' => 'Créé le',            'value' => $model->created_at,                                       'type' => 'date'],
            ];

            if ($model->last_login_at) {
                $detailRows[] = ['label' => 'Dernière connexion', 'value' => $model->last_login_at, 'type' => 'date'];
            }

            if ($model->email_verified_at) {
                $detailRows[] = ['label' => 'Email vérifié le', 'value' => $model->email_verified_at, 'type' => 'date'];
            }
        }

        return [
            'route_base'    => 'admin.users',
            'route_base_id' => 'user',
            'permission'    => 'users',
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
            'stat_cards'    => [],
            'charts'        => [],
            'quick_actions' => [],
        ];
    }
}
