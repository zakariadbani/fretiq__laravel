<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectBatchItem extends Model
{
    use HasFactory, Validator;

    public const STATUSES = ['pending', 'processing', 'review', 'ready', 'promoted', 'failed', 'skipped'];

    protected $table = 'prospect_batch_items';

    /** @var array<string> */
    protected $fillable = [
        'prospect_batch_id',
        'row_number',
        'original_input',
        'company_name',
        'normalized_name',
        'country',
        'city',
        'provided_domain',
        'selected_domain',
        'registrable_domain',
        'domain_alternatives',
        'domain_confidence',
        'domain_reason',
        'status',
        'company_id',
        'source_metadata',
        'error_code',
        'error_message',
        'processing_started_at',
        'processed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'row_number' => 'integer',
        'domain_alternatives' => 'array',
        'domain_confidence' => 'integer',
        'source_metadata' => 'array',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProspectBatch::class, 'prospect_batch_id');
    }

    public function importedContacts(): HasMany
    {
        return $this->hasMany(ProspectBatchContact::class);
    }

    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'prospect_batch_id' => 'required|integer|exists:prospect_batches,id',
            'row_number' => 'required|integer|min:1',
            'original_input' => 'required|string',
            'company_name' => 'required|string|max:255',
            'normalized_name' => 'required|string|max:255',
            'country' => 'nullable|string|size:2',
            'city' => 'nullable|string|max:120',
            'provided_domain' => 'nullable|string|max:191',
            'selected_domain' => 'nullable|string|max:191',
            'registrable_domain' => 'nullable|string|max:191',
            'domain_alternatives' => 'nullable|array',
            'domain_alternatives.*' => 'string|max:191',
            'domain_confidence' => 'nullable|integer|between:0,100',
            'domain_reason' => 'nullable|string|max:64',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'company_id' => 'nullable|integer|exists:companies,id',
            'source_metadata' => 'nullable|array',
            'error_code' => 'nullable|string|max:64',
            'error_message' => 'nullable|string',
            'processing_started_at' => 'nullable|date',
            'processed_at' => 'nullable|date',
        ];
    }
}
