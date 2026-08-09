<?php

namespace App\Models\Zoho;

class ZohoActionsCommercial extends ZohoMirror
{
    protected $table = 'zoho_actions_commercials';

    protected function casts(): array
    {
        return parent::casts() + ['action_at' => 'datetime', 'due_at' => 'datetime'];
    }
}
