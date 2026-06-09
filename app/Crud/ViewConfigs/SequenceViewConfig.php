<?php

namespace App\Crud\ViewConfigs;

use App\Models\Sequence;

/**
 * ViewConfig descriptor for the Sequence detail/edit pages.
 *
 * Consumed by:
 *   - SequenceController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 */
class SequenceViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Sequence|null  $model
     * @param  array|null     $stats  Optional pre-computed stats array.
     *                                When provided, real KPI values are injected into stat_cards.
     */
    public static function make(?Sequence $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Step count (for badge on steps tab) ───────────────────────────────
        $stepsCount = null;
        if ($hasId) {
            // Use withCount eager-loaded value when available, else count relation
            $stepsCount = isset($model->steps_count)
                ? (int) $model->steps_count
                : $model->steps()->count();
        }

        // ── Subtitle pills ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            $subtitle[] = [
                'icon' => 'bi-list-ol',
                'text' => $stepsCount . ' étape' . ($stepsCount !== 1 ? 's' : ''),
            ];
            $subtitle[] = [
                'icon' => $model->stop_on_reply ? 'bi-reply-fill' : 'bi-reply',
                'text' => $model->stop_on_reply ? 'Stop si réponse' : 'Continue sur réponse',
            ];
        }

        // ── Hero tiles ────────────────────────────────────────────────────────
        $tiles = [];
        if ($hasId) {
            $activeColor = $model->is_active ? 'success' : 'secondary';
            $activeLabel = $model->is_active ? 'Actif' : 'Inactif';

            $stopColor = $model->stop_on_reply ? 'success' : 'secondary';
            $stopLabel = $model->stop_on_reply ? 'Oui' : 'Non';

            $tiles = [
                ['icon' => 'bi-toggle-on',  'color' => $activeColor, 'value' => $activeLabel,   'caption' => 'Actif'],
                ['icon' => 'bi-reply-fill',  'color' => $stopColor,   'value' => $stopLabel,      'caption' => 'Stop si réponse'],
                ['icon' => 'bi-list-ol',     'color' => 'primary',    'value' => $stepsCount ?? 0, 'caption' => 'Étapes'],
            ];
        }

        // ── Status-bar toggle (null when no id — omit on create) ─────────────
        $toggle = null;
        if ($hasId) {
            $toggle = [
                'field'       => 'is_active',
                'route'       => route('admin.sequences.executeSwitch', $model->id),
                'permission'  => 'edit sequences',
                'title'       => 'Séquence active',
                'description' => 'Activer cette séquence pour permettre l\'envoi automatique des étapes.',
                'success'     => 'Séquence mise à jour',
                'error'       => 'Échec de la mise à jour',
                'icon'        => 'bi-check-circle-fill',
            ];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────
        // sequence_apercu  — Aperçu   (view page)
        // sequence_general — Général  (edit page)
        // sequence_steps   — Étapes   (both pages — native pane, out_of_form on edit)
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',     'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-layers',   'mode' => 'edit'],
            [
                'key'         => 'steps',
                'label'       => 'Étapes',
                'icon'        => 'bi-list-ol',
                'mode'        => 'both',
                'count'       => $stepsCount,
                'out_of_form' => true,
            ],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            $detailRows = [
                ['label' => 'Nom',              'value' => $model->name,          'type' => 'text'],
                ['label' => 'Actif',            'value' => $model->is_active,     'type' => 'boolean'],
                ['label' => 'Stop si réponse',  'value' => $model->stop_on_reply, 'type' => 'boolean'],
                ['label' => 'Nombre d\'étapes', 'value' => $stepsCount,           'type' => 'text'],
                ['label' => 'Créé le',          'value' => $model->created_at,    'type' => 'date'],
            ];
        }

        // ── Stat cards ────────────────────────────────────────────────────────
        // Contacts inscrits: from enrollments relation if cheap enough
        $enrollmentsTotal = null;
        if ($hasId) {
            // Use withCount when available, otherwise count relation
            $enrollmentsTotal = isset($model->enrollments_count)
                ? (int) $model->enrollments_count
                : $model->enrollments()->count();
        }

        $statCards = [
            [
                'icon'  => 'bi-person-check',
                'color' => 'primary',
                'label' => 'Contacts inscrits',
                'value' => $hasId ? $enrollmentsTotal : null,
                'hint'  => null,
            ],
            [
                'icon'  => 'bi-envelope',
                'color' => 'info',
                'label' => 'Emails envoyés via séquence',
                'value' => $stats['emails_sent'] ?? null,
                'hint'  => ($stats === null || !isset($stats['emails_sent']))
                    ? 'Disponible après le lancement des séquences'
                    : null,
            ],
        ];

        return [
            'route_base'    => 'admin.sequences',
            'route_base_id' => 'sequence',
            'permission'    => 'sequences',
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
