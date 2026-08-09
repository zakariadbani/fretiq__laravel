<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoQuoteStatusHistory extends ZohoMirror
{
    protected $table = 'zoho_quote_status_history';

    protected function casts(): array
    {
        return parent::casts() + ['occurred_at' => 'datetime'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ZohoQuote::class, 'quote_zoho_id', 'zoho_id');
    }
}
