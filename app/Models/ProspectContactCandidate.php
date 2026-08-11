<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProspectContactCandidate extends Model
{
    use HasFactory, Validator;

    public const DECISIONS = ['pending', 'approved', 'rejected', 'promoted'];

    protected $table = 'prospect_contact_candidates';

    /** @var array<string> */
    protected $fillable = [
        'prospect_batch_id',
        'prospect_batch_item_id',
        'company_id',
        'email',
        'normalized_email',
        'name',
        'position',
        'phone',
        'source',
        'email_kind',
        'verification_status',
        'verification_checked_at',
        'verification_source',
        'decision',
        'decision_reason',
        'decided_by',
        'decided_at',
        'contact_id',
        'metadata',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'verification_checked_at' => 'datetime',
        'decided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProspectBatch::class, 'prospect_batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ProspectBatchItem::class, 'prospect_batch_item_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'prospect_batch_id' => 'required|integer|exists:prospect_batches,id',
            'prospect_batch_item_id' => 'required|integer|exists:prospect_batch_items,id',
            'company_id' => 'nullable|integer|exists:companies,id',
            'email' => 'required|email:rfc|max:191',
            'normalized_email' => 'required|email:rfc|max:191',
            'name' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:50',
            'source' => 'required|string|max:32',
            'email_kind' => 'nullable|string|max:12',
            'verification_status' => 'nullable|string|max:16',
            'verification_checked_at' => 'nullable|date',
            'verification_source' => 'nullable|string|max:32',
            'decision' => 'nullable|in:'.implode(',', self::DECISIONS),
            'decision_reason' => 'nullable|string|max:64',
            'decided_by' => 'nullable|integer|exists:users,id',
            'decided_at' => 'nullable|date',
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'metadata' => 'nullable|array',
        ];
    }
}
