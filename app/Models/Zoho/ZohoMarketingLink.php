<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ZohoMarketingLink extends Model
{
    protected $table = 'zoho_marketing_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'audit_metadata' => 'array',
            'matched_at' => 'datetime',
            'validated_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'is_active' => 'boolean',
            'validation_count' => 'integer',
            'invalidation_count' => 'integer',
        ];
    }

    public function scopeActiveExactEmail(Builder $query): Builder
    {
        return $query->where('match_type', 'exact_email')->where('is_active', true);
    }
}
