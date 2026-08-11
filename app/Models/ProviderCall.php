<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderCall extends Model
{
    use Validator;

    protected $table = 'provider_calls';

    /** @var array<string> */
    protected $fillable = [
        'prospect_batch_id',
        'prospect_batch_item_id',
        'provider',
        'operation',
        'engine',
        'idempotency_key',
        'status',
        'http_status',
        'duration_ms',
        'result_count',
        'reserved_units',
        'consumed_units',
        'provider_request_id',
        'attempt_count',
        'metadata',
        'retry_at',
        'started_at',
        'finished_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'http_status' => 'integer',
        'duration_ms' => 'integer',
        'result_count' => 'integer',
        'reserved_units' => 'decimal:2',
        'consumed_units' => 'decimal:2',
        'attempt_count' => 'integer',
        'metadata' => 'array',
        'retry_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProspectBatch::class, 'prospect_batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProspectBatchItem::class, 'prospect_batch_item_id');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'prospect_batch_id' => 'nullable|integer|exists:prospect_batches,id',
            'prospect_batch_item_id' => 'nullable|integer|exists:prospect_batch_items,id',
            'provider' => 'required|string|max:24',
            'operation' => 'required|string|max:48',
            'engine' => 'nullable|string|max:32',
            'idempotency_key' => 'required|string|size:64',
            'status' => 'nullable|string|max:16',
            'http_status' => 'nullable|integer|between:100,599',
            'duration_ms' => 'nullable|integer|min:0',
            'result_count' => 'nullable|integer|min:0',
            'reserved_units' => 'nullable|numeric|min:0',
            'consumed_units' => 'nullable|numeric|min:0',
            'provider_request_id' => 'nullable|string|max:191',
            'attempt_count' => 'nullable|integer|between:0,255',
            'metadata' => 'nullable|array',
            'retry_at' => 'nullable|date',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
        ];
    }
}
