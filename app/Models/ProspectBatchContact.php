<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable batch provenance for automatically imported discovery contacts. */
class ProspectBatchContact extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['prospect_batch_id', 'prospect_batch_item_id', 'contact_id', 'provider_source', 'imported_at'];

    protected $casts = ['imported_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProspectBatch::class, 'prospect_batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProspectBatchItem::class, 'prospect_batch_item_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
