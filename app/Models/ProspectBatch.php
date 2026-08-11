<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProspectBatch extends Model
{
    use HasFactory, Validator;

    public const STATUSES = ['draft', 'queued', 'running', 'review', 'completed', 'failed', 'cancelled'];

    public const SOURCES = ['company_list', 'discover', 'recovery'];

    protected $table = 'prospect_batches';

    /** @var array<string> */
    protected $fillable = [
        'source_type',
        'name',
        'created_by',
        'prospect_criteria_id',
        'status',
        'source_fingerprint',
        'quality_preset',
        'quality_settings',
        'source_options',
        'source_cursor',
        'estimate',
        'recovery_audit',
        'cost_confirmed_at',
        'total_items',
        'processed_items',
        'review_items',
        'failed_items',
        'promoted_companies',
        'candidate_contacts',
        'started_at',
        'finished_at',
        'error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quality_settings' => 'array',
        'source_options' => 'array',
        'source_cursor' => 'array',
        'estimate' => 'array',
        'recovery_audit' => 'encrypted:array',
        'cost_confirmed_at' => 'datetime',
        'total_items' => 'integer',
        'processed_items' => 'integer',
        'review_items' => 'integer',
        'failed_items' => 'integer',
        'promoted_companies' => 'integer',
        'candidate_contacts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ProspectBatchItem::class);
    }

    public function contactCandidates(): HasMany
    {
        return $this->hasMany(ProspectContactCandidate::class);
    }

    public function providerCalls(): HasMany
    {
        return $this->hasMany(ProviderCall::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(ProspectCriteria::class, 'prospect_criteria_id');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'source_type' => 'required|in:'.implode(',', self::SOURCES),
            'name' => 'required|string|max:255',
            'created_by' => 'nullable|integer|exists:users,id',
            'prospect_criteria_id' => 'nullable|integer|exists:prospect_criteria,id',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'source_fingerprint' => 'nullable|string|size:64',
            'quality_preset' => 'nullable|string|max:16',
            'quality_settings' => 'nullable|array',
            'source_options' => 'nullable|array',
            'source_cursor' => 'nullable|array',
            'estimate' => 'nullable|array',
            'recovery_audit' => 'nullable|array',
            'cost_confirmed_at' => 'nullable|date',
            'total_items' => 'nullable|integer|min:0',
            'processed_items' => 'nullable|integer|min:0',
            'review_items' => 'nullable|integer|min:0',
            'failed_items' => 'nullable|integer|min:0',
            'promoted_companies' => 'nullable|integer|min:0',
            'candidate_contacts' => 'nullable|integer|min:0',
            'started_at' => 'nullable|date',
            'finished_at' => 'nullable|date',
            'error' => 'nullable|string',
        ];
    }
}
