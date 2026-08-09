<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoSyncCheckpoint extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'zoho_sync_checkpoints';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'module',
        'submodule',
        'sync_mode',
        'page_query_fingerprint',
        'cursor_modified_time',
        'cursor_zoho_id',
        'page_last_zoho_id',
        'cursor_at',
        'reconcile_cursor_zoho_id',
        'reconcile_correlation_id',
        'reconcile_started_at',
        'cursor_page_token',
        'page_token_expires_at',
        'status',
        'lease_owner',
        'lease_expires_at',
        'heartbeat_at',
        'retry_count',
        'completed_at',
        'counters',
        'correlation_id',
        'sync_batch_id',
        'generation',
        'delivery_retry_deadline_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cursor_modified_time' => 'datetime',
        'cursor_at' => 'datetime',
        'reconcile_started_at' => 'datetime',
        'page_token_expires_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'completed_at' => 'datetime',
        'retry_count' => 'integer',
        'sync_batch_id' => 'integer',
        'generation' => 'integer',
        'delivery_retry_deadline_at' => 'datetime',
        'counters' => 'array',
    ];
}
