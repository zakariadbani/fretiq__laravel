<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ZohoStandardSyncWorkItem extends Model
{
    protected $guarded = ['id'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ZohoStandardSyncRun::class, 'zoho_standard_sync_run_id');
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'delivery_generation' => 'integer',
            'lease_expires_at' => 'datetime',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'records_unchanged' => 'integer',
            'api_requests' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
