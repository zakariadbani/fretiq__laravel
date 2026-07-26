<?php

namespace App\Crud\ViewConfigs;

use App\Models\Suppression;

class SuppressionViewConfig
{
    public static function make(?Suppression $model): array
    {
        $hasId = $model && $model->id;
        $reason = $hasId ? config('global.data.suppression_reasons.' . $model->reason) : null;
        $source = $hasId ? config('global.data.suppression_sources.' . $model->source) : null;

        return [
            'route_base'    => 'admin.suppressions',
            'route_base_id' => 'suppression',
            'permission'    => 'suppressions',
            'title'         => $hasId ? $model->email : '',
            'avatar'        => [
                'type'  => 'initials',
                'value' => $hasId ? $model->email : '',
                'color' => 'danger',
            ],
            'badges'        => array_values(array_filter([
                $reason ? ['label' => $reason['label'], 'color' => $reason['color']] : null,
                $source ? ['label' => $source['label'], 'color' => $source['color']] : null,
            ])),
            'subtitle'      => $hasId ? [
                ['icon' => 'bi-envelope', 'text' => $model->email],
                ['icon' => 'bi-calendar3', 'text' => 'Ajoutée le ' . $model->created_at?->format('d/m/Y H:i')],
            ] : [],
            'tiles'         => [],
            'toggle'        => null,
            'tabs'          => [
                ['key' => 'apercu', 'label' => 'Aperçu', 'icon' => 'bi-grid', 'mode' => 'view'],
                ['key' => 'general', 'label' => 'Général', 'icon' => 'bi-slash-circle', 'mode' => 'edit'],
            ],
        ];
    }
}
