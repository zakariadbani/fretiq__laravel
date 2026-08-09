<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoSyncLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'zoho_sync_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'module',
        'submodule',
        'mode',
        'sync_batch_id',
        'correlation_id',
        'synced_at',
        'records_synced',
        'records_seen',
        'records_created',
        'records_updated',
        'records_unchanged',
        'records_deleted',
        'records_quarantined',
        'status',
        'error',
        'duration_ms',
        'cursor_zoho_id',
        'cursor_at',
        'api_requests',
        'api_credits',
        'telemetry',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'synced_at' => 'datetime',
        'cursor_at' => 'datetime',
        'sync_batch_id' => 'integer',
        'records_synced' => 'integer',
        'records_seen' => 'integer',
        'records_created' => 'integer',
        'records_updated' => 'integer',
        'records_unchanged' => 'integer',
        'records_deleted' => 'integer',
        'records_quarantined' => 'integer',
        'duration_ms' => 'integer',
        'api_requests' => 'integer',
        'api_credits' => 'integer',
        'telemetry' => 'array',
    ];
}
