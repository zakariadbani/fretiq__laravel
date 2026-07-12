<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRun extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'campaign_runs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'campaign_id',
        'occurrence_key',
        'run_at',
        'status',
        'stats_sent',
        'stats_delivered',
        'stats_opened',
        'stats_clicked',
        'stats_bounced',
        'stats_unsubscribed',
        'stats_replied',
        'conversion_count',
        'zoho_list_key',
        'zoho_campaign_key',
        'driver_ref',
        'started_at',
        'finished_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'run_at'      => 'datetime',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The parent campaign.
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * All individual recipient records for this run.
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
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
            'campaign_id'    => 'required|integer|exists:campaigns,id',
            'occurrence_key' => 'required|string|max:64',
            'run_at'         => 'required|date',
            'status'         => 'nullable|' . ConfigEnum::in('campaign_run_statuses'),
            'stats_sent'         => 'nullable|integer|min:0',
            'stats_delivered'    => 'nullable|integer|min:0',
            'stats_opened'       => 'nullable|integer|min:0',
            'stats_clicked'      => 'nullable|integer|min:0',
            'stats_bounced'      => 'nullable|integer|min:0',
            'stats_unsubscribed' => 'nullable|integer|min:0',
            'stats_replied'      => 'nullable|integer|min:0',
            'conversion_count'   => 'nullable|integer|min:0',
        ];
    }

    // ── Computed metrics ───────────────────────────────────────────────────────

    /**
     * Open rate as a percentage (0–100). Guards against division by zero.
     */
    public function openRate(): float
    {
        if ((int) $this->stats_delivered === 0) {
            return 0.0;
        }

        return round(($this->stats_opened / $this->stats_delivered) * 100, 2);
    }

    /**
     * Click rate as a percentage (0–100). Guards against division by zero.
     */
    public function clickRate(): float
    {
        if ((int) $this->stats_delivered === 0) {
            return 0.0;
        }

        return round(($this->stats_clicked / $this->stats_delivered) * 100, 2);
    }

    /**
     * Conversion rate as a percentage (0–100). Guards against division by zero.
     */
    public function conversionRate(): float
    {
        if ((int) $this->stats_delivered === 0) {
            return 0.0;
        }

        return round(($this->conversion_count / $this->stats_delivered) * 100, 2);
    }
}
