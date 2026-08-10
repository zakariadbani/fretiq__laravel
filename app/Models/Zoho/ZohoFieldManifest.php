<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Model;

class ZohoFieldManifest extends Model
{
    protected $table = 'zoho_field_manifests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'layouts' => 'array', 'picklists' => 'array', 'related_lists' => 'array', 'mapping_gaps' => 'array', 'is_current' => 'boolean', 'verified_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
