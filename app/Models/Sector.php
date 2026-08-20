<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sectors';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'label',
        'is_active',
        'use_in_discovery',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'use_in_discovery' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Default listing order: manual sort_order first, then label alphabetically.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('ordered', function (Builder $builder) {
            $builder->orderBy('sort_order')->orderBy('label');
        });
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
            'label' => 'required|string|max:100|unique:sectors,label,' . $this->id,
            'is_active' => 'boolean',
            'use_in_discovery' => 'boolean',
            'sort_order' => 'integer|min:0',
        ];
    }
}
