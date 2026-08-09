<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;

class ZohoSyncBatch extends Model
{
    protected $table = 'zoho_sync_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modules' => 'array', 'counters' => 'array', 'requested_at' => 'datetime',
            'scheduled_for' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime',
            'post_reconciliation_lease_expires_at' => 'datetime',
            'post_reconciliation_started_at' => 'datetime', 'post_reconciliation_completed_at' => 'datetime',
            'post_reconciliation_attempts' => 'integer',
            'post_reconciliation_retry_not_before' => 'datetime',
            'post_reconciliation_retry_deadline_at' => 'datetime',
        ];
    }
}
