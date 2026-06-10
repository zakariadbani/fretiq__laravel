<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProspectCriteria extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'prospect_criteria';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'sectors',
        'countries',
        'company_sizes',
        'target_positions',
        'daily_limit',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'sectors'          => 'array',
        'countries'        => 'array',
        'company_sizes'    => 'array',
        'target_positions' => 'array',
        'daily_limit'      => 'integer',
        'is_active'        => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Companies targeted by this criteria set.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'criteria_id');
    }

    /**
     * All discovery runs for this criteria set.
     */
    public function discoveryRuns(): HasMany
    {
        return $this->hasMany(DiscoveryRun::class, 'prospect_criteria_id');
    }

    /**
     * The most recent discovery run (uses latestOfMany for a single-row eager-load).
     */
    public function latestDiscoveryRun(): HasOne
    {
        return $this->hasOne(DiscoveryRun::class, 'prospect_criteria_id')->latestOfMany();
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     * The Validator trait calls this via validator() / validate().
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'               => 'required|string|max:100',
            'sectors'            => 'nullable|array|max:50',
            'sectors.*'          => 'string|max:100',
            'countries'          => 'nullable|array|max:50',
            'countries.*'        => 'string|max:10',
            'company_sizes'      => 'nullable|array|max:20',
            'company_sizes.*'    => 'string|max:20',
            'target_positions'   => 'nullable|array|max:50',
            'target_positions.*' => 'string|max:100',
            'daily_limit'        => 'nullable|integer|min:1|max:500',
            'is_active'          => 'boolean',
        ];
    }
}
