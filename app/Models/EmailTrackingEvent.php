<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmailTrackingEvent extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_tracking_events';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'trackable_type',
        'trackable_id',
        'token',
        'event',
        'first_human_open_at',
        'last_human_open_at',
        'human_open_count',
        'machine_open_count',
        'last_opened_ip',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'first_human_open_at' => 'datetime',
        'last_human_open_at'  => 'datetime',
    ];

    // ── Factories ──────────────────────────────────────────────────────────────

    /**
     * Create (or reuse) the sent-event row for a trackable send.
     *
     * Shared by CampaignService, SequenceService, and SendSmtpReservationJob —
     * trackable_type/id derive from $trackable (CampaignRecipient or
     * SequenceStepSend); default payload (event='sent', zero counters)
     * matches all prior inline firstOrCreate() calls.
     *
     * Reuses an existing row for this trackable if one already exists,
     * instead of always minting a fresh token-keyed row. $token is a fresh
     * random token (never collides with a real row), so keying only on it
     * gave zero protection against a caller retried before the send ever
     * completed (queue retry, a stuck/never-advancing send reprocessed
     * repeatedly) — every retry orphaned a brand-new row bound to the same
     * trackable_id. That is exactly how ~437k garbage rows piled up in prod
     * (2026-07, all trackable_type=SequenceStepSend, one never-advancing
     * step send). Look-up-then-create is not atomic (no unique index on
     * trackable_type+trackable_id), but the real send attempt is already
     * serialized upstream (SmtpSendReservation / SequenceStepSend unique
     * constraints), so a concurrent double-insert here is not realistically
     * reachable.
     */
    public static function createForSend(Model $trackable, string $token): self
    {
        return static::where('trackable_type', get_class($trackable))
            ->where('trackable_id', $trackable->id)
            ->latest('id')
            ->first()
            ?? static::firstOrCreate(
                ['token' => $token],
                [
                    'trackable_type'     => get_class($trackable),
                    'trackable_id'       => $trackable->id,
                    'event'              => 'sent',
                    'human_open_count'   => 0,
                    'machine_open_count' => 0,
                ],
            );
    }

    /** Promote a paced pre-send reservation after provider acceptance. */
    public function markSent(): void
    {
        if ($this->event !== 'sent' && $this->event !== 'clicked') {
            $this->update(['event' => 'sent']);
        }
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Polymorphic parent (CampaignRecipient, sequence_step_sends, etc.).
     * No DB-level FK — orphans are pruned at the job layer.
     */
    public function trackable(): MorphTo
    {
        return $this->morphTo();
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
            'trackable_type'     => 'required|string|max:255',
            'trackable_id'       => 'required|integer',
            'token'              => 'required|string|size:64|unique:email_tracking_events,token,' . $this->id,
            'event'              => 'nullable|string|max:12',
            'human_open_count'   => 'nullable|integer|min:0',
            'machine_open_count' => 'nullable|integer|min:0',
            'last_opened_ip'     => 'nullable|ip',
        ];
    }
}
