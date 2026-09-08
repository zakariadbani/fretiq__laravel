<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Segment extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'segments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'scope',
        'is_manual',
        'filter',
        'last_built_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'filter'        => 'array',
        'is_manual'     => 'boolean',
        'last_built_at' => 'datetime',
    ];

    /**
     * Accessor overrides the plain boolean cast: Eloquent's primitive-cast
     * short-circuit returns raw null unchanged for a NULL is_manual column,
     * so callers that require a real bool (SegmentService::resolveAudience())
     * would get null. Coerce here so is_manual is always a real bool.
     */
    public function getIsManualAttribute($value)
    {
        return (bool) $value;
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     *
     * Filter uses nested rules so each field is independently validated.
     * The Validator trait passes rules() straight to \Validator::make(), which
     * supports dot-notation nested keys natively — no special handling needed.
     *
     * @return array<string, string|array<string>>
     */
    public function rules(): array
    {
        $countryCodes  = implode(',', array_keys(config('global.data.company_countries', [])));
        $lifecycleStates = implode(',', array_keys(config('global.data.contact_lifecycle_states', [])));

        return [
            'name'  => 'required|string|max:255',
            'scope'     => 'required|' . ConfigEnum::in('segment_scopes'),
            'is_manual' => 'nullable|boolean',

            // Top-level filter: optional array
            'filter'          => 'nullable|array',

            // filter.sector: optional array of up to 20 free-text sector strings
            'filter.sector'   => 'nullable|array|max:20',
            'filter.sector.*' => 'string|max:100',

            // filter.country: optional array of up to 20 valid ISO-3166-1 alpha-2 codes
            'filter.country'   => 'nullable|array|max:20',
            'filter.country.*' => 'string|size:2|in:' . $countryCodes,

            // filter.lifecycle_state: optional calculated contact-state value
            'filter.lifecycle_state'   => 'nullable|string|in:' . $lifecycleStates,

            // filter.criteria_id: optional array of up to 20 prospect_criteria ids
            'filter.criteria_id'   => 'nullable|array|max:20',
            'filter.criteria_id.*' => 'integer|exists:prospect_criteria,id',

            // filter.position: optional array of up to 20 free-text position tags
            'filter.position'   => 'nullable|array|max:20',
            'filter.position.*' => 'string|max:100',

            // filter.exclude_contacted / filter.exclude_generic_mailbox: booleans
            'filter.exclude_contacted'        => 'nullable|boolean',
            'filter.exclude_generic_mailbox'  => 'nullable|boolean',

            // filter.engagement: optional structured campaign-engagement sub-filter.
            'filter.engagement'                => 'nullable|array',
            'filter.engagement.campaign_id'    => 'nullable|array|max:20',
            'filter.engagement.campaign_id.*'  => 'integer|exists:campaigns,id',
            'filter.engagement.opened'         => 'nullable|boolean',
            'filter.engagement.clicked'        => 'nullable|boolean',
            'filter.engagement.sans_demande'   => 'nullable|boolean',

            // Opt-in campaign admission policy.  SegmentService intentionally
            // ignores this metadata: it is consumed only by paced SMTP campaigns.
            'filter.prospecting_rules' => 'nullable|array',
            'filter.prospecting_rules.enabled' => 'nullable|boolean',
            'filter.prospecting_rules.contact_gap_days' => 'nullable|integer|min:1|max:365',
            'filter.prospecting_rules.verification_max_age_days' => 'nullable|integer|min:1|max:365',
            'filter.prospecting_rules.one_contact_per_company' => 'nullable|boolean',
            'filter.prospecting_rules.once_per_sequence_company' => 'nullable|boolean',
            'filter.prospecting_rules.exclude_engaged_companies' => 'nullable|boolean',
            'filter.prospecting_rules.exclude_pending_companies' => 'nullable|boolean',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    /**
     * All pinned contacts (include + exclude modes combined).
     *
     * Contact uses SoftDeletes — BelongsToMany automatically applies the related
     * model's global scope, so soft-deleted contacts are excluded from all results.
     */
    public function pinnedContacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_segment')
            ->withPivot('mode')
            ->withTimestamps();
    }

    /**
     * Contacts pinned in include mode (force-added to the resolved audience).
     */
    public function includedContacts(): BelongsToMany
    {
        return $this->pinnedContacts()->wherePivot('mode', 'include');
    }

    /**
     * Contacts pinned in exclude mode (unconditionally removed after dedup).
     */
    public function excludedContacts(): BelongsToMany
    {
        return $this->pinnedContacts()->wherePivot('mode', 'exclude');
    }

    /**
     * IDs of contacts pinned in include mode.
     *
     * @return array<int>
     */
    public function includedContactIds(): array
    {
        return $this->includedContacts()->pluck('contacts.id')->toArray();
    }

    /**
     * IDs of contacts pinned in exclude mode.
     *
     * @return array<int>
     */
    public function excludedContactIds(): array
    {
        return $this->excludedContacts()->pluck('contacts.id')->toArray();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Returns the true deliverable contact count for this segment.
     *
     * Delegates to SegmentService::resolveWithStats() so the count reflects
     * ALL audience stages (scope + filter + suppression + dedup) — identical
     * to the count that resolve() would produce.
     *
     * B2: threads pinned include/exclude id-sets into resolveWithStats so the
     * displayed count reflects manual pins, not filter-only.
     *
     * Previously this method applied scope only (join on companies), which caused
     * the displayed count to diverge from actual send recipients. That divergence
     * is fixed here (D5 + D12).
     *
     * @return int
     */
    public function contactsCount(): int
    {
        try {
            return app(\App\Services\Campaign\SegmentService::class)
                ->resolveWithStats($this->scope, $this->filter ?? [], false, $this->includedContactIds(), $this->excludedContactIds(), $this->is_manual)['final'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('segments.contactsCount failed', ['segment_id' => $this->id, 'message' => $e->getMessage()]);
            return 0;
        }
    }
}
