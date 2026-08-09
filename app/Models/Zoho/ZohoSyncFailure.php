<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;

class ZohoSyncFailure extends Model
{
    protected $table = 'zoho_sync_failures';

    protected $guarded = ['id'];

    protected $hidden = ['context'];

    protected function casts(): array
    {
        return ['context' => 'array', 'retry_after' => 'datetime', 'resolved_at' => 'datetime'];
    }
}
