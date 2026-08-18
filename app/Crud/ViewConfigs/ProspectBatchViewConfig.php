<?php

namespace App\Crud\ViewConfigs;

use App\Models\ProspectBatch;

final class ProspectBatchViewConfig
{
    /**
     * @param  array{status_counts:array<string,int>,imported_contacts_count:int}|null  $stats
     *         Grouped-by-status item counts (ProspectBatchController::view()) — the
     *         only source for tab badges and stat cards. Never the persisted
     *         total_items/processed_items/review_items counters: those lag the
     *         live item state (see ProspectReviewWorkspace::batchProgress() for the
     *         same reasoning). Null on the edit page (form.blade.php's
     *         _header-with-tabs fallback build has no batch-scoped query to run) —
     *         tab badges are simply omitted in that case.
     * @param  ?int  $companyCount  COUNT(DISTINCT company_id) for this batch's
     *         items, computed once by the caller — avoid recomputing it, the
     *         hero _header-actions partial needs the exact same number.
     */
    public static function make(?ProspectBatch $model, ?array $stats = null, ?int $companyCount = null): array
    {
        $hasId = $model?->exists === true;
        $status = $hasId ? self::status((string) $model->status) : ['label' => 'Brouillon', 'color' => 'secondary'];
        $source = $hasId ? self::source((string) $model->source_type) : ['label' => 'Liste', 'color' => 'primary'];
        $counts = $stats['status_counts'] ?? [];
        $resultsCount = $stats === null ? null : array_sum($counts);
        $reviewCount = $stats === null ? null : (($counts['review'] ?? 0) + ($counts['failed'] ?? 0));

        // Same two batch actions as _header-actions.blade.php (the hero
        // buttons) — only built when the tab/stats machinery is actually
        // rendered (never on the edit-page tabbar shim, which calls make()
        // with $stats === null) and under the same visibility rule: batch
        // linked to a criteria, past draft/queued/running.
        $quickActions = [];
        if ($hasId && $stats !== null
            && $model->prospect_criteria_id !== null
            && ! in_array($model->status, ['draft', 'queued', 'running'], true)
        ) {
            $csrfToken = csrf_token();
            $modelId = (int) $model->id;
            $companyCount ??= (int) $model->items()->whereNotNull('company_id')->distinct('company_id')->count('company_id');

            $quickActions[] = [
                'label' => 'Chercher les contacts manquants',
                'icon' => 'bi-person-plus-fill',
                'color' => 'light-primary',
                'permission' => 'enrich companies',
                'onclick' => 'launchBatchContactEnrichment(this)',
                'attrs' => [
                    'data-preview-url' => route('admin.prospect_batches.contact_enrichment_preview', $modelId),
                    'data-dispatch-url' => route('admin.prospect_batches.contact_enrichment_dispatch', $modelId),
                    'data-csrf-token' => $csrfToken,
                ],
            ];
            $quickActions[] = [
                'label' => 'Relancer le scoring IA',
                'icon' => 'bi-arrow-repeat',
                'color' => 'light-warning',
                'permission' => 'run prospect resolution',
                'onclick' => 'launchBatchRescore(this)',
                'attrs' => [
                    'data-dispatch-url' => route('admin.prospect_batches.rescore_dispatch', $modelId),
                    'data-status-base-url' => route('admin.prospect_batches.rescore_status', $modelId),
                    'data-csrf-token' => $csrfToken,
                    'data-company-count' => $companyCount,
                ],
            ];
        }

        return [
            'route_base' => 'admin.prospect_batches',
            'route_base_id' => 'prospect_batch',
            'permission' => 'prospect_batches',
            'title' => $hasId ? $model->name : 'Nouveau lot',
            'avatar' => ['type' => 'icon', 'value' => 'bi-building-add', 'color' => 'primary'],
            'badges' => $hasId ? [
                ['label' => $status['label'], 'color' => $status['color']],
                ['label' => $source['label'], 'color' => $source['color']],
            ] : [],
            'subtitle' => $hasId ? [
                ['icon' => 'bi-calendar3', 'text' => $model->created_at?->format('d/m/Y H:i') ?? '—'],
            ] : [],
            'tiles' => $hasId ? [
                ['icon' => 'bi-building', 'color' => 'primary', 'value' => (string) $model->total_items, 'caption' => 'Entreprises'],
                ['icon' => 'bi-check2-circle', 'color' => 'success', 'value' => (string) $model->processed_items, 'caption' => 'Traitées'],
                ['icon' => 'bi-exclamation-diamond', 'color' => 'warning', 'value' => (string) $model->review_items, 'caption' => 'À revoir'],
                ['icon' => 'bi-person-lines-fill', 'color' => 'info', 'value' => (string) $model->imported_contacts, 'caption' => 'Contacts importés'],
            ] : [],
            'toggle' => null,
            // 'Paramètres' only exists while the batch is a draft — it is the
            // create-wizard's steps 2-4 (see ProspectBatchController::edit()),
            // which redirect away once the batch is launched. Hiding the tab
            // for a launched batch avoids a tab that just bounces back here.
            // All three view-mode tabs are native panes on the view page
            // (mode 'view') — no more separate /review route/page.
            'tabs' => [
                ['key' => 'apercu', 'label' => 'Aperçu', 'icon' => 'bi-grid', 'mode' => 'view'],
                ['key' => 'resultats', 'label' => 'Résultats', 'icon' => 'bi-building-check', 'mode' => 'view', 'count' => $resultsCount],
                ...(! $hasId || $model->status === 'draft')
                    ? [['key' => 'general', 'label' => 'Paramètres', 'icon' => 'bi-sliders', 'mode' => 'edit']]
                    : [],
                ...($hasId && $model->status !== 'draft' && (auth()->user()?->can('review prospect matches') ?? false))
                    ? [['key' => 'review', 'label' => 'À vérifier', 'icon' => 'bi-exclamation-diamond', 'mode' => 'view', 'count' => $reviewCount]]
                    : [],
            ],
            'detail_rows' => $hasId ? [
                ['label' => 'Source', 'value' => $source['label'], 'type' => 'text'],
                ['label' => 'Qualité', 'value' => ucfirst((string) $model->quality_preset), 'type' => 'text'],
                ['label' => 'Coût confirmé', 'value' => $model->cost_confirmed_at, 'type' => 'date'],
                ['label' => 'Début', 'value' => $model->started_at, 'type' => 'date'],
                ['label' => 'Fin', 'value' => $model->finished_at, 'type' => 'date'],
            ] : [],
            'stat_cards' => $stats === null ? [] : [
                ['icon' => 'bi-check2-circle', 'color' => 'success', 'label' => 'Traitées', 'value' => ($counts['ready'] ?? 0) + ($counts['promoted'] ?? 0) + ($counts['skipped'] ?? 0)],
                ['icon' => 'bi-question-circle', 'color' => 'warning', 'label' => 'À décider', 'value' => $counts['review'] ?? 0],
                ['icon' => 'bi-arrow-repeat', 'color' => 'danger', 'label' => 'À relancer', 'value' => $counts['failed'] ?? 0],
                ['icon' => 'bi-hourglass-split', 'color' => 'primary', 'label' => 'En file / en cours', 'value' => ($counts['pending'] ?? 0) + ($counts['processing'] ?? 0)],
                ['icon' => 'bi-person-lines-fill', 'color' => 'info', 'label' => 'Contacts importés', 'value' => $stats['imported_contacts_count'] ?? 0],
            ],
            'charts' => [],
            'quick_actions' => $quickActions,
        ];
    }

    /** @return array{label:string,color:string} */
    private static function status(string $key): array
    {
        return config("global.data.prospect_batch_statuses.{$key}", match ($key) {
            'queued' => ['label' => 'En attente', 'color' => 'info'],
            'running' => ['label' => 'En cours', 'color' => 'primary'],
            'review' => ['label' => 'À revoir', 'color' => 'warning'],
            'completed' => ['label' => 'Terminé', 'color' => 'success'],
            'failed' => ['label' => 'Échec', 'color' => 'danger'],
            'cancelled' => ['label' => 'Annulé', 'color' => 'secondary'],
            default => ['label' => 'Brouillon', 'color' => 'secondary'],
        });
    }

    /** @return array{label:string,color:string} */
    private static function source(string $key): array
    {
        return config("global.data.prospect_batch_sources.{$key}", match ($key) {
            'discover' => ['label' => 'Discover IA', 'color' => 'info'],
            'recovery' => ['label' => 'Récupération', 'color' => 'warning'],
            default => ['label' => 'Liste', 'color' => 'primary'],
        });
    }
}
