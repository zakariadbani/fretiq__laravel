<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'campaign_recipients';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'campaign_run_id',
        'company_dispatch_id',
        'contact_id',
        'status',
        'skip_reason',
        'provider_message_id',
        'bounce_reason',
        'bounce_type',
        'sent_at',
        'opened_at',
        'clicked_at',
        'bounced_at',
        'replied_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at'     => 'datetime',
        'opened_at'   => 'datetime',
        'clicked_at'  => 'datetime',
        'bounced_at'  => 'datetime',
        'replied_at'  => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The campaign run this recipient belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class, 'campaign_run_id');
    }

    /**
     * The contact being targeted.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** Company-level paced dispatch owning this frozen recipient. */
    public function companyDispatch(): BelongsTo
    {
        return $this->belongsTo(CampaignCompanyDispatch::class, 'company_dispatch_id');
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
            'campaign_run_id'     => 'required|integer|exists:campaign_runs,id',
            'company_dispatch_id' => 'nullable|integer|exists:campaign_company_dispatches,id',
            'contact_id'          => 'required|integer|exists:contacts,id',
            'status'              => 'nullable|' . ConfigEnum::in('campaign_recipient_statuses'),
            'skip_reason'         => 'nullable|string|max:100',
            'provider_message_id' => 'nullable|string|max:191',
            'bounce_reason'       => 'nullable|string|max:255',
            'bounce_type'         => 'nullable|string|max:16',
            'sent_at'             => 'nullable|date',
            'opened_at'           => 'nullable|date',
            'clicked_at'          => 'nullable|date',
            'bounced_at'          => 'nullable|date',
            'replied_at'          => 'nullable|date',
        ];
    }
}
