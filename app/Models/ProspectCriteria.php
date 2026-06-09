<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'name'             => 'required|string|max:100',
            'sectors'          => 'nullable|array',
            'countries'        => 'nullable|array',
            'company_sizes'    => 'nullable|array',
            'target_positions' => 'nullable|array',
            'daily_limit'      => 'nullable|integer|min:1|max:500',
            'is_active'        => 'boolean',
        ];
    }
}
