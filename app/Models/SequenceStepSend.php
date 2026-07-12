<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceStepSend extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sequence_step_sends';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'enrollment_id',
        'step_no',
        'provider_message_id',
        'status',
        'sent_at',
        'opened_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at'   => 'datetime',
        'opened_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The enrollment this send record belongs to.
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(SequenceEnrollment::class, 'enrollment_id');
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
            'enrollment_id'       => 'required|integer|exists:sequence_enrollments,id',
            'step_no'             => 'required|integer|min:1',
            'provider_message_id' => 'nullable|string|max:191',
            'status'              => 'nullable|' . ConfigEnum::in('sequence_step_statuses'),
            'sent_at'             => 'nullable|date',
            'opened_at'           => 'nullable|date',
        ];
    }
}
