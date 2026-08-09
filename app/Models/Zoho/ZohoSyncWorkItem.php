<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;

final class ZohoSyncWorkItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'retry_after' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
