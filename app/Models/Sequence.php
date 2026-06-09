<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sequence extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sequences';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'is_active',
        'stop_on_reply',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active'     => 'boolean',
        'stop_on_reply' => 'boolean',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * Steps that make up this sequence, ordered by their position.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('step_no');
    }

    /**
     * All contact enrollments in this sequence.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
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
            'name'          => 'required|string|max:255',
            'is_active'     => 'nullable|boolean',
            'stop_on_reply' => 'nullable|boolean',
        ];
    }
}
