<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ZohoStandardSyncRun extends Model
{
    protected $guarded = ['id'];

    public function workItems(): HasMany
    {
        return $this->hasMany(ZohoStandardSyncWorkItem::class, 'zoho_standard_sync_run_id');
    }

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'watermark_at' => 'datetime',
            'since_at' => 'datetime',
            'page_token_expires_at' => 'datetime',
            'enumeration_restart_count' => 'integer',
            'counters' => 'array',
            'enumerated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
