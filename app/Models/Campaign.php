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

    public const AMBIGUOUS_ZOHO_DRIVER_REFS = ['zoho-send-attempted', 'zoho-send-uncertain'];

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
        'sequence_enrollment_mode',
        'daily_company_limit',
        'scheduled_at',
        'recurrence',
        'next_run_at',
        'timezone',
        'send_window',
        'is_active',
        'sequence_auto_enroll_enabled',
        'driver',
        'delivery_channel',
        'smtp_daily_email_limit',
        'delivery_started_at',
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
        'sequence_auto_enroll_enabled' => 'boolean',
        'daily_company_limit' => 'integer',
        'smtp_daily_email_limit' => 'integer',
        'delivery_started_at' => 'datetime',
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

    /** Company-level progress ledger for paced campaigns. */
    public function companyDispatches(): HasMany
    {
        return $this->hasMany(CampaignCompanyDispatch::class);
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

    /** Defensive runtime default for paced campaigns created before the field existed. */
    public function pacedDailyCompanyLimit(): int
    {
        return max(1, (int) ($this->daily_company_limit ?? 20));
    }

    public function effectiveDeliveryChannel(): string
    {
        if (in_array($this->delivery_channel, ['zoho', 'smtp'], true)) {
            return $this->delivery_channel;
        }

        return config('services.zoho.driver', 'local') === 'zoho' || $this->driver === 'zoho'
            ? 'zoho'
            : 'smtp';
    }

    public function usesZohoDriver(): bool
    {
        return $this->effectiveDeliveryChannel() === 'zoho';
    }

    public function smtpDailyEmailLimit(): int
    {
        return $this->smtp_daily_email_limit === null
            ? 20
            : max(0, (int) $this->smtp_daily_email_limit);
    }

    public function deliverySettingsLocked(): bool
    {
        if ($this->delivery_started_at !== null) {
            return true;
        }

        if (! $this->exists) return false;
        // A queued SMTP run is intentionally editable until a mailbox has
        // accepted a message. Only a narrow live transport claim blocks a race.
        if ($this->delivery_channel === 'smtp') {
            $smtpEvidenceExists = \App\Models\SmtpSendReservation::query()
                ->where('campaign_id', $this->id)
                ->where(function ($query): void {
                    $query->whereIn('status', ['accepted', 'sent', 'uncertain'])
                        ->orWhere(function ($sending): void {
                            $sending->where('status', 'sending')
                                ->whereNotNull('lease_expires_at')
                                ->where('lease_expires_at', '>', now());
                        });
                })
                ->exists();

            if ($smtpEvidenceExists) {
                return true;
            }

            // A regular SMTP run stays "sending" while future recipients are
            // merely reserved, so that run status alone must not freeze edits.
            return $this->runs()
                ->whereIn('driver_ref', self::AMBIGUOUS_ZOHO_DRIVER_REFS)
                ->exists();
        }

        return $this->hasRunDeliveryEvidence();
    }

    public function hasRunDeliveryEvidence(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->runs()
            ->where(function ($query): void {
                $query->whereIn('status', ['sending', 'sent'])
                    ->orWhereIn('driver_ref', self::AMBIGUOUS_ZOHO_DRIVER_REFS);
            })
            ->exists();
    }

    public function markDeliveryStarted(): void
    {
        if (! $this->exists) {
            return;
        }

        self::query()
            ->whereKey($this->getKey())
            ->whereNull('delivery_started_at')
            ->update(['delivery_started_at' => now()]);

        $this->refresh();
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
            if ($model->sequence_enrollment_mode === null || $model->sequence_enrollment_mode === '') {
                $model->sequence_enrollment_mode = 'immediate';
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
        $isPacedSequence = $this->schedule_type === 'sequence'
            && $this->sequence_enrollment_mode === 'paced';

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
            'sequence_enrollment_mode' => [
                Rule::requiredIf(fn () => $this->schedule_type === 'sequence' && $this->usesZohoDriver()),
                'nullable',
                Rule::in($this->schedule_type === 'sequence' && $this->usesZohoDriver() ? ['paced'] : ['immediate', 'paced']),
            ],
            'scheduled_at'       => 'nullable|date',
            'recurrence'         => 'nullable|array',
            'next_run_at'        => [
                'nullable',
                Rule::requiredIf(fn () => (in_array($this->schedule_type, ['recurring', 'paced'], true) && $isActive) || $isPacedSequence),
                'date',
            ],
            'daily_company_limit' => [
                'nullable',
                Rule::requiredIf(fn () => ($this->schedule_type === 'paced' && $isActive) || $isPacedSequence),
                'integer',
                'min:1',
            ],
            'timezone'           => [Rule::requiredIf(fn () => $isPacedSequence), 'nullable', 'timezone'],
            'send_window'        => 'nullable|array',
            'is_active'          => 'nullable|boolean',
            'driver'             => 'nullable|in:local,zoho',
            'delivery_channel'   => 'nullable|in:zoho,smtp',
            'smtp_daily_email_limit' => 'nullable|integer|min:0|max:500',
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
            'next_run_at.required' => 'Le champ Premier envoi est obligatoire pour activer cette campagne.',
            'daily_company_limit.required' => 'Le nombre de sociétés par jour est obligatoire pour un envoi progressif.',
        ];
    }

}
