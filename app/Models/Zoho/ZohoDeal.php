<?php

namespace App\Models\Zoho;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZohoDeal extends ZohoMirror
{
    protected $table = 'zoho_deals';

    protected function casts(): array
    {
        return parent::casts() + ['amount' => 'decimal:2', 'probability' => 'decimal:4', 'weighted_amount' => 'decimal:2', 'closing_date' => 'date'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ZohoAccount::class, 'account_zoho_id', 'zoho_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ZohoContact::class, 'contact_zoho_id', 'zoho_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(ZohoQuote::class, 'deal_zoho_id', 'zoho_id');
    }
}
