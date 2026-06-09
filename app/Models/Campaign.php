<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'name',
        'subject',
        'schedule_type',
        'scheduled_at',
        'recurrence',
        'next_run_at',
        'timezone',
        'send_window',
        'status',
        'driver',
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
     * All dispatch runs for this campaign.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(CampaignRun::class);
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
            'name'               => 'required|string|max:255',
            'segment_id'         => 'required|integer|exists:segments,id',
            'template_id'        => 'required|integer|exists:campaign_templates,id',
            'sender_identity_id' => 'required|integer|exists:sender_identities,id',
            'subject'            => 'nullable|string|max:255',
            'schedule_type'      => 'nullable|in:' . implode(',', array_keys(config('global.data.schedule_types', []))),
            'scheduled_at'       => 'nullable|date',
            'recurrence'         => 'nullable|array',
            'next_run_at'        => 'nullable|date',
            'timezone'           => 'nullable|string|max:64',
            'send_window'        => 'nullable|array',
            'status'             => 'nullable|in:' . implode(',', array_keys(config('global.data.campaign_statuses', []))),
            'driver'             => 'nullable|in:local,zoho',
        ];
    }

    // ── Accessors / Helpers ────────────────────────────────────────────────────

    /**
     * Returns the Metronic badge CSS class for the current status value.
     * Example: 'badge-light-secondary' for 'draft'.
     */
    public function statusBadgeClass(): string
    {
        $color = config('global.data.campaign_statuses.' . $this->status . '.color', 'secondary');

        return 'badge-light-' . $color;
    }
}
