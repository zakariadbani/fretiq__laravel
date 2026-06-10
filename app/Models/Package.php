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
        'price_monthly',
        'is_active',
        'sort_order',
    ];

    /**
     * The attributes that should be cast.
     *
     * null is meaningful for daily_credits (= unlimited); the cast preserves null.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'daily_credits' => 'integer',
        'price_monthly' => 'decimal:2',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
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
     * Whether this package imposes no daily credit cap.
     *
     * IMPORTANT: every consumer of daily_credits must branch on isUnlimited()
     * before any arithmetic — PHP footgun: min(20, null) === null and
     * null <= 0 === true, so null must never reach min() / comparisons.
     */
    public function isUnlimited(): bool
    {
        return $this->daily_credits === null;
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
            'name'          => 'required|string|max:255',
            'daily_credits' => 'nullable|integer|min:0',
            'price_monthly' => 'nullable|numeric|min:0',
            'is_active'     => 'boolean',
            'sort_order'    => 'integer|min:0',
        ];
    }
}
