<?php

namespace App\Models\Zoho;

use App\Models\Zoho\Concerns\HasZohoMirrorState;
use Illuminate\Database\Eloquent\Model;

abstract class ZohoMirror extends Model
{
    use HasZohoMirrorState;

    protected $guarded = ['id'];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'zoho_created_at' => 'datetime',
            'zoho_modified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'zoho_deleted_at' => 'datetime',
        ];
    }
}
