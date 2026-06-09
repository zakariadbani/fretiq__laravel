<?php

namespace App\Crud\ViewConfigs;

use App\Models\Demande;
use Illuminate\Support\Facades\Route;

/**
 * ViewConfig descriptor for the Demande detail/edit pages.
 *
 * Consumed by:
 *   - DemandeController (injected as $viewConfig into view/edit/create)
 *   - @include('backend.partials.crud._tabbar', ['config' => $viewConfig, ...])
 *   - @include('backend.partials.crud._apercu',  ['config' => $viewConfig, ...])
 *
 * Domain focus: the WIN list — which campaign/sequence generated this RFQ.
 * Source-chain attribution is the key information; the hero makes it obvious.
 */
class DemandeViewConfig
{
    /**
     * Build the full config array for $model.
     * Pass null (or a model without ->id) for create mode.
     *
     * @param  Demande|null  $model
     * @param  array|null    $stats  (not used — kept for API parity; stat_cards honest-empty)
     */
    public static function make(?Demande $model, ?array $stats = null): array
    {
        $hasId = $model && $model->id;

        // ── Status config ─────────────────────────────────────────────────────
        $statusCfg   = $hasId ? config('global.data.demande_statuses.' . $model->status, []) : [];
        $statusLabel = $statusCfg['label'] ?? null;
        $statusColor = $statusCfg['color'] ?? 'secondary';

        // ── Source chain — campaign or sequence ───────────────────────────────
        $hasCampaign = $hasId && $model->campaign;
        $hasSequence = $hasId && $model->sequence;

        // Source tile: campaign name takes precedence
        if ($hasCampaign) {
            $sourceLabel = $model->campaign->name;
            $sourceCaption = 'Campagne';
            $sourceColor = 'info';
            $sourceIcon = 'bi-megaphone';
        } elseif ($hasSequence) {
            $sourceLabel = $model->sequence->name;
            $sourceCaption = 'Séquence';
            $sourceColor = 'primary';
            $sourceIcon = 'bi-list-ol';
        } else {
            $sourceLabel = '—';
            $sourceCaption = 'Source';
            $sourceColor = 'secondary';
            $sourceIcon = 'bi-question-circle';
        }

        // ── Subtitle items ────────────────────────────────────────────────────
        $subtitle = [];
        if ($hasId) {
            // Contact email with mailto
            if ($model->contact && $model->contact->email) {
                $subtitle[] = [
                    'icon' => 'bi-envelope',
                    'text' => $model->contact->email,
                    'href' => 'mailto:' . $model->contact->email,
                ];
            }
            // Company name with link
            if ($model->contact && $model->contact->company) {
                $companyHref = Route::has('admin.companies.view')
                    ? route('admin.companies.view', $model->contact->company_id)
                    : null;
                $subtitle[] = [
                    'icon' => 'bi-building',
                    'text' => $model->contact->company->name,
                    'href' => $companyHref,
                ];
            }
            // Captured at
            if ($model->captured_at) {
                $subtitle[] = [
                    'icon' => 'bi-calendar3',
                    'text' => 'Capturé le ' . $model->captured_at->format('d/m/Y'),
                    'href' => null,
                ];
            }
        }

        // ── Hero title ────────────────────────────────────────────────────────
        // Use contact name if available, otherwise fall back to #id
        $title = '';
        if ($hasId) {
            $title = ($model->contact && $model->contact->name)
                ? $model->contact->name
                : ('Demande #' . $model->id);
        }

        // ── Badges (status) ───────────────────────────────────────────────────
        $badges = [];
        if ($hasId && $statusLabel) {
            $badges[] = ['label' => $statusLabel, 'color' => $statusColor];
        }

        // ── No boolean toggle — Demande has no is_active field ─────────────
        $toggle = null;

        // ── Tabs (view + general/edit only) ───────────────────────────────────
        $tabs = [
            ['key' => 'apercu',  'label' => 'Aperçu',  'icon' => 'bi-grid',    'mode' => 'view'],
            ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-inbox',   'mode' => 'edit'],
        ];

        // ── Detail rows ───────────────────────────────────────────────────────
        $detailRows = [];
        if ($hasId) {
            // Contact — link to admin.contacts.view
            $contactHref = ($model->contact && Route::has('admin.contacts.view'))
                ? route('admin.contacts.view', $model->contact_id)
                : null;
            $detailRows[] = [
                'label' => 'Contact',
                'value' => $model->contact ? $model->contact->name : null,
                'type'  => 'link',
                'href'  => $contactHref,
            ];

            // Entreprise — link to admin.companies.view
            $companyName = $model->contact && $model->contact->company
                ? $model->contact->company->name
                : null;
            $companyHref = ($model->contact && $model->contact->company_id && Route::has('admin.companies.view'))
                ? route('admin.companies.view', $model->contact->company_id)
                : null;
            $detailRows[] = [
                'label' => 'Entreprise',
                'value' => $companyName,
                'type'  => 'link',
                'href'  => $companyHref,
            ];

            // Campagne — link to admin.campaigns.view if route exists
            $campaignName = $model->campaign ? $model->campaign->name : null;
            $campaignHref = ($model->campaign_id && Route::has('admin.campaigns.view'))
                ? route('admin.campaigns.view', $model->campaign_id)
                : null;
            $detailRows[] = [
                'label' => 'Campagne',
                'value' => $campaignName,
                'type'  => 'link',
                'href'  => $campaignHref,
            ];

            // Séquence — link to admin.sequences.view if route exists
            $sequenceName = $model->sequence ? $model->sequence->name : null;
            $sequenceHref = ($model->sequence_id && Route::has('admin.sequences.view'))
                ? route('admin.sequences.view', $model->sequence_id)
                : null;
            $detailRows[] = [
                'label' => 'Séquence',
                'value' => $sequenceName,
                'type'  => 'link',
                'href'  => $sequenceHref,
            ];

            // Statut — enum badge
            $detailRows[] = [
                'label'     => 'Statut',
                'value'     => $model->status,
                'type'      => 'enum',
                'configKey' => 'demande_statuses',
            ];

            // Type de demande
            $detailRows[] = [
                'label' => 'Type',
                'value' => $model->kind,
                'type'  => 'text',
            ];

            // Capturé le
            $detailRows[] = [
                'label' => 'Capturé le',
                'value' => $model->captured_at,
                'type'  => 'date',
            ];

            // Créé le
            $detailRows[] = [
                'label' => 'Créé le',
                'value' => $model->created_at,
                'type'  => 'date',
            ];

            // Notes — conditional, only when present
            if ($model->notes) {
                $detailRows[] = [
                    'label' => 'Notes',
                    'value' => $model->notes,
                    'type'  => 'text',
                ];
            }
        }

        // ── Stat cards — honest-empty (no KPIs for this module) ─────────────
        // Demande is about the WIN list / attribution. Keep 0 cards (attribution
        // is in detail_rows; stat_cards would be phantom numbers here).
        $statCards = [];

        // ── Quick actions ─────────────────────────────────────────────────────
        $quickActions = [];
        if ($hasId) {
            // Voir le contact
            if ($model->contact_id && Route::has('admin.contacts.view')) {
                $quickActions[] = [
                    'label'      => 'Voir le contact',
                    'icon'       => 'bi-person',
                    'color'      => 'light-primary',
                    'permission' => 'view contacts',
                    'href'       => route('admin.contacts.view', $model->contact_id),
                ];
            }
            // Voir l'entreprise
            if ($model->contact && $model->contact->company_id && Route::has('admin.companies.view')) {
                $quickActions[] = [
                    'label'      => 'Voir l\'entreprise',
                    'icon'       => 'bi-building',
                    'color'      => 'light-info',
                    'permission' => 'view companies',
                    'href'       => route('admin.companies.view', $model->contact->company_id),
                ];
            }
        }

        return [
            'route_base'    => 'admin.demandes',
            'route_base_id' => 'demande',
            'permission'    => 'demandes',
            'title'         => $title,
            'avatar'        => [
                'type'  => 'initials',
                'value' => $title ?: 'D',
                'color' => 'warning',
            ],
            'badges'        => $badges,
            'subtitle'      => $subtitle,
            'tiles'         => $hasId ? [
                ['icon' => 'bi-patch-check', 'color' => $statusColor, 'value' => $statusLabel ?? '—',  'caption' => 'Statut'],
                ['icon' => $sourceIcon,       'color' => $sourceColor, 'value' => $sourceLabel,          'caption' => $sourceCaption],
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
