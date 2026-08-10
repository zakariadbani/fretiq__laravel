<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZohoQuote extends ZohoMirror
{
    protected $table = 'zoho_quotes';

    protected function casts(): array
    {
        return parent::casts() + [
            'grand_total' => 'decimal:2', 'sub_total' => 'decimal:2', 'discount' => 'decimal:2',
            'tax' => 'decimal:2', 'line_items_total' => 'decimal:2', 'line_items_total_complete' => 'boolean', 'exchange_rate' => 'decimal:6', 'valid_till' => 'date',
            'transport_type' => 'array', 'quote_date' => 'date', 'incoterms' => 'array', 'stackability' => 'array', 'tags' => 'array', 'transit_time_days' => 'integer', 'last_activity_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ZohoQuoteItem::class, 'zoho_quote_id', 'zoho_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(ZohoDeal::class, 'deal_zoho_id', 'zoho_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ZohoAccount::class, 'account_zoho_id', 'zoho_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ZohoContact::class, 'contact_zoho_id', 'zoho_id');
    }
}
