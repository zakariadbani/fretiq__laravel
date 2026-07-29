<?php

namespace App\Crud\ViewConfigs;

use App\Models\InboxEmail;

class InboxViewConfig
{
    public static function make(?InboxEmail $model): array
    {
        $hasId = $model && $model->id;
        $status = $hasId ? config('global.data.inbox_statuses.' . $model->status, []) : [];
        $recipientReplied = $hasId && $model->campaignRecipient?->status === 'replied';

        return [
            'route_base' => 'admin.inbox',
            'route_base_id' => 'inbox',
            'permission' => 'inbox',
            'title' => $hasId ? ($model->subject ?: '(sans objet)') : '',
            'avatar' => ['type' => 'initials', 'value' => $hasId ? $model->from_email : '', 'color' => 'primary'],
            'badges' => array_values(array_filter([
                $status ? ['label' => $status['label'] ?? $model->status, 'color' => $status['color'] ?? 'secondary'] : null,
                $recipientReplied ? ['label' => 'Répondu', 'color' => 'success'] : null,
            ])),
            'subtitle' => $hasId ? [
                ['icon' => 'bi-envelope', 'text' => $model->from_email],
                ['icon' => 'bi-calendar3', 'text' => $model->received_at?->format('d/m/Y H:i') ?? 'Date inconnue'],
            ] : [],
            'tiles' => [],
            'toggle' => null,
            'tabs' => [['key' => 'apercu', 'label' => 'Message', 'icon' => 'bi-envelope-open', 'mode' => 'view']],
        ];
    }
}
