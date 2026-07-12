<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suppression extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'suppressions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'email',
        'contact_id',
        'reason',
        'source',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The contact associated with this suppressed address (nullable — survives contact deletion).
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    // ── Lifecycle hooks ────────────────────────────────────────────────────────

    /**
     * Coerce empty/null enum columns to their DB defaults before any insert or
     * update so we never send an explicit NULL into a NOT NULL column.
     */
    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->reason === null || $model->reason === '') {
                $model->reason = 'manual';
            }
            if ($model->source === null || $model->source === '') {
                $model->source = 'manual';
            }
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
            'email'      => 'required|email|max:191|unique:suppressions,email,' . $this->id,
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'reason'     => 'nullable|' . ConfigEnum::in('suppression_reasons'),
            'source'     => 'nullable|' . ConfigEnum::in('suppression_sources'),
        ];
    }

    // ── Static helpers ─────────────────────────────────────────────────────────

    /**
     * Returns true if the given email address is on the suppression list.
     * Used as a pre-send gate in the dispatch pipeline.
     */
    public static function isSuppressed(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }
}
