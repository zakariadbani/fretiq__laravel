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
