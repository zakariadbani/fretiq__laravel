<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Campaign extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'campaigns';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'segment_id',
        'template_id',
        'sender_identity_id',
        'sequence_id',
        'name',
        'subject',
        'schedule_type',
        'scheduled_at',
        'recurrence',
        'next_run_at',
        'timezone',
        'send_window',
        'is_active',
        'driver',
        'zoho_list_key',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_at' => 'datetime',
        'next_run_at'  => 'datetime',
        'recurrence'   => 'array',
        'send_window'  => 'array',
        'is_active'    => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The segment (audience) this campaign targets.
     */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    /**
     * The email template used for this campaign.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'template_id');
    }

    /**
     * The sender identity (from-name + from-email) for this campaign.
     */
    public function senderIdentity(): BelongsTo
    {
        return $this->belongsTo(SenderIdentity::class);
    }

    /**
     * The sequence associated with this campaign (sequence-type campaigns).
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /**
     * All dispatch runs for this campaign.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class);
    }

    /**
     * All sequence enrollments attributed to this campaign.
     */
    public function sequenceEnrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function scheduleTimezone(): string
    {
        return $this->timezone ?: 'Europe/Paris';
    }

    public function effectiveScheduledAt(): ?Carbon
    {
        return $this->next_run_at ?? $this->scheduled_at;
    }

    public function scheduledAtLocal(): ?Carbon
    {
        return $this->effectiveScheduledAt()?->copy()->setTimezone($this->scheduleTimezone());
    }

    public function isOverdue(?Carbon $now = null): bool
    {
        $scheduledAt = $this->effectiveScheduledAt();

        return $this->is_active
            && $this->schedule_type !== 'sequence'
            && $scheduledAt !== null
            && $scheduledAt->lessThan($now ?? now());
    }

    // ── Lifecycle hooks ────────────────────────────────────────────────────────

    /**
     * Coerce empty/null enum columns to their DB defaults before any insert or
     * update so we never send an explicit NULL into a NOT NULL column.
     */
    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->driver === null || $model->driver === '') {
                $model->driver = 'local';
            }
            if ($model->schedule_type === null || $model->schedule_type === '') {
                $model->schedule_type = 'one_shot';
            }
            if ($model->timezone === null || $model->timezone === '') {
                $model->timezone = 'Europe/Paris';
            }
        });
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $isActive = in_array($this->is_active, [true, 1, '1', 'true', 'on'], true);

        return [
            'name'               => 'required|string|max:255',
            'segment_id'         => 'required|integer|exists:segments,id',
            // W1: template not required in sequence mode — each step carries its own template.
            'template_id'        => 'required_unless:schedule_type,sequence|nullable|integer|exists:campaign_templates,id',
            'sender_identity_id' => 'required|integer|exists:sender_identities,id',
            // sequence_id required when schedule_type is 'sequence'.
            'sequence_id'        => 'nullable|required_if:schedule_type,sequence|integer|exists:sequences,id',
            'subject'            => 'nullable|string|max:255',
            'schedule_type'      => 'nullable|' . ConfigEnum::in('schedule_types'),
            'scheduled_at'       => 'nullable|date',
            'recurrence'         => 'nullable|array',
            'next_run_at'        => [
                'nullable',
                Rule::requiredIf(fn () => $this->schedule_type === 'recurring' && $isActive),
                'date',
            ],
            'timezone'           => 'nullable|timezone',
            'send_window'        => 'nullable|array',
            'is_active'          => 'nullable|boolean',
            'driver'             => 'nullable|in:local,zoho',
        ];
    }

    /**
     * Campaign-specific validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'next_run_at.required' => 'Le champ Premier envoi est obligatoire pour activer une campagne récurrente.',
        ];
    }

}
