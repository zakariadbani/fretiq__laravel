<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;

final class ZohoBulkReadJob extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lease_expires_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'retry_after' => 'datetime',
            'started_at' => 'datetime',
            'watermark_at' => 'datetime',
            'completed_at' => 'datetime',
            'counters' => 'array',
            'terminalization_context' => 'array',
            'delivery_generation' => 'integer',
        ];
    }
}
