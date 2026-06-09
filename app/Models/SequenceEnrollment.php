<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceEnrollment extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sequence_enrollments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'sequence_id',
        'contact_id',
        'campaign_id',
        'current_step',
        'status',
        'next_send_at',
        'last_sent_at',
        'stopped_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'next_send_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The sequence this enrollment belongs to.
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /**
     * The enrolled contact.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The campaign that triggered this enrollment (nullable).
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Individual step-send records for this enrollment.
     */
    public function stepSends(): HasMany
    {
        return $this->hasMany(SequenceStepSend::class, 'enrollment_id');
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'sequence_id'    => 'required|integer|exists:sequences,id',
            'contact_id'     => 'required|integer|exists:contacts,id',
            'campaign_id'    => 'nullable|integer|exists:campaigns,id',
            'current_step'   => 'nullable|integer|min:0',
            'status'         => 'nullable|in:' . implode(',', array_keys(config('global.data.sequence_enrollment_statuses', []))),
            'next_send_at'   => 'nullable|date',
            'last_sent_at'   => 'nullable|date',
            'stopped_reason' => 'nullable|string|max:100',
        ];
    }
}
