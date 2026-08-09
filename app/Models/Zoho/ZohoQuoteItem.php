<?php

namespace App\Models\Zoho;

use App\Models\Zoho\Concerns\HasZohoMirrorState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZohoQuoteItem extends Model
{
    use HasZohoMirrorState;

    protected $table = 'zoho_quote_items';

    protected $guarded = ['id'];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array', 'quantity' => 'decimal:4', 'list_price' => 'decimal:2', 'unit_price' => 'decimal:2', 'discount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'zoho_created_at' => 'datetime', 'zoho_modified_at' => 'datetime', 'last_seen_at' => 'datetime', 'last_synced_at' => 'datetime', 'zoho_deleted_at' => 'datetime'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ZohoQuote::class, 'zoho_quote_id', 'zoho_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ZohoProduct::class, 'product_zoho_id', 'zoho_id');
    }
}
