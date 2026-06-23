<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends Model
{
    use Validator;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'daily_credits',
        'monthly_credits',
        'daily_contact_credits',
        'monthly_contact_credits',
        'quota_anchor_date',
        'price_monthly',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * null is meaningful for daily_credits, daily_contact_credits, monthly_credits,
     * and monthly_contact_credits (= unlimited for each meter); the cast preserves null.
     * quota_anchor_date is cast to Carbon\Carbon so period math uses the same type
     * as the rest of the quota layer.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'daily_credits'           => 'integer',
        'monthly_credits'         => 'integer',
        'daily_contact_credits'   => 'integer',
        'monthly_contact_credits' => 'integer',
        'quota_anchor_date'       => 'date',
        'price_monthly'           => 'decimal:2',
        'is_active'               => 'boolean',
        'sort_order'              => 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * All assignments that have used this package.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(PackageAssignment::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Whether this package imposes no daily cap on the COMPANY/DISCOVERY meter.
     *
     * daily_credits           = company/discovery meter (one credit per company found).
     * daily_contact_credits   = contact/enrichment meter (one credit per contact enriched).
     *
     * IMPORTANT: every consumer of daily_credits must branch on isUnlimited()
     * before any arithmetic — PHP footgun: min(20, null) === null and
     * null <= 0 === true, so null must never reach min() / comparisons.
     *
     * @see contactIsUnlimited() for the parallel contact/enrichment meter guard.
     */
    public function isUnlimited(): bool
    {
        return $this->daily_credits === null;
    }

    /**
     * Whether this package imposes no daily cap on the CONTACT/ENRICHMENT meter.
     *
     * Mirrors isUnlimited() for daily_contact_credits.
     *
     * IMPORTANT: every consumer of daily_contact_credits must branch on
     * contactIsUnlimited() before any arithmetic — same PHP null footgun applies.
     */
    public function contactIsUnlimited(): bool
    {
        return $this->daily_contact_credits === null;
    }

    /**
     * Whether this package imposes no monthly cap on the COMPANY/DISCOVERY meter.
     *
     * monthly_credits = null means no monthly limit (unlimited by convention).
     *
     * IMPORTANT: every consumer of monthly_credits must branch on monthlyIsUnlimited()
     * before any arithmetic — same PHP null footgun as isUnlimited().
     *
     * @see monthlyContactIsUnlimited() for the parallel monthly contact meter guard.
     */
    public function monthlyIsUnlimited(): bool
    {
        return $this->monthly_credits === null;
    }

    /**
     * Whether this package imposes no monthly cap on the CONTACT/ENRICHMENT meter.
     *
     * monthly_contact_credits = null means no monthly contact limit.
     *
     * IMPORTANT: every consumer of monthly_contact_credits must branch on
     * monthlyContactIsUnlimited() before any arithmetic — same PHP null footgun applies.
     */
    public function monthlyContactIsUnlimited(): bool
    {
        return $this->monthly_contact_credits === null;
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model (used by the Validator trait).
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'                    => 'required|string|max:255',
            'daily_credits'           => 'nullable|integer|min:0',
            'monthly_credits'         => 'nullable|integer|min:0',
            'daily_contact_credits'   => 'nullable|integer|min:0',
            'monthly_contact_credits' => 'nullable|integer|min:0',
            // Anchor validated past-or-today: future anchors would cause signed-diff
            // truncation bugs in currentPeriod() (seed floor assumes anchor <= on).
            'quota_anchor_date'       => 'nullable|date|before_or_equal:today',
            'price_monthly'           => 'nullable|numeric|min:0',
            'is_active'               => 'boolean',
            'sort_order'              => 'integer|min:0',
        ];
    }
}
