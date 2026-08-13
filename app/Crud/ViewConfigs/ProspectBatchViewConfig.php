<?php

namespace App\Crud\ViewConfigs;

use App\Models\ProspectBatch;

final class ProspectBatchViewConfig
{
    public static function make(?ProspectBatch $model): array
    {
        $hasId = $model?->exists === true;
        $status = $hasId ? self::status((string) $model->status) : ['label' => 'Brouillon', 'color' => 'secondary'];
        $source = $hasId ? self::source((string) $model->source_type) : ['label' => 'Liste', 'color' => 'primary'];

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
            'tabs' => [
                ['key' => 'apercu', 'label' => 'Aperçu', 'icon' => 'bi-grid', 'mode' => 'view'],
                ['key' => 'general', 'label' => 'Paramètres', 'icon' => 'bi-sliders', 'mode' => 'edit'],
            ],
            'detail_rows' => $hasId ? [
                ['label' => 'Source', 'value' => $source['label'], 'type' => 'text'],
                ['label' => 'Qualité', 'value' => ucfirst((string) $model->quality_preset), 'type' => 'text'],
                ['label' => 'Coût confirmé', 'value' => $model->cost_confirmed_at, 'type' => 'date'],
                ['label' => 'Début', 'value' => $model->started_at, 'type' => 'date'],
                ['label' => 'Fin', 'value' => $model->finished_at, 'type' => 'date'],
            ] : [],
            'stat_cards' => [],
            'charts' => [],
            'quick_actions' => [],
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
