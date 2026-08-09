<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoActivity extends ZohoMirror
{
    protected $table = 'zoho_activities';

    protected function casts(): array
    {
        return parent::casts() + ['activity_at' => 'datetime', 'due_at' => 'datetime', 'start_at' => 'datetime', 'end_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ZohoContact::class, 'contact_zoho_id', 'zoho_id');
    }
}
