<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoDealStageHistory extends ZohoMirror
{
    protected $table = 'zoho_deal_stage_history';

    protected function casts(): array
    {
        return parent::casts() + [
            'occurred_at' => 'datetime', 'amount' => 'decimal:2', 'probability' => 'decimal:4',
            'expected_revenue' => 'decimal:2', 'closing_date' => 'date',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(ZohoDeal::class, 'deal_zoho_id', 'zoho_id');
    }
}
