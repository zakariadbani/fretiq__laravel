<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Demande extends Model
{
    use Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'demandes';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'contact_id',
        'campaign_id',
        'campaign_run_id',
        'sequence_id',
        'kind',
        'status',
        'notes',
        'captured_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'captured_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The contact who originated the demande (nullable — survives GDPR erasure).
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The campaign associated with this demande (nullable).
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The specific campaign run associated with this demande (nullable).
     */
    public function campaignRun(): BelongsTo
    {
        return $this->belongsTo(CampaignRun::class);
    }

    /**
     * The sequence associated with this demande (nullable).
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    // ── Lifecycle hooks ────────────────────────────────────────────────────────

    /**
     * Coerce empty/null enum columns to their DB defaults before any insert or
     * update so we never send an explicit NULL into a NOT NULL column.
     */
    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            if ($model->status === null || $model->status === '') {
                $model->status = 'pending';
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
            'contact_id'      => 'nullable|integer|exists:contacts,id',
            'campaign_id'     => 'nullable|integer|exists:campaigns,id',
            'campaign_run_id' => 'nullable|integer|exists:campaign_runs,id',
            'sequence_id'     => 'nullable|integer|exists:sequences,id',
            'kind'            => 'nullable|string|max:32',
            'status'          => 'nullable|in:' . implode(',', array_keys(config('global.data.demande_statuses', []))),
            'notes'           => 'nullable|string',
            'captured_at'     => 'required|date',
        ];
    }

    // ── Accessors / Helpers ────────────────────────────────────────────────────

    /**
     * Returns the Metronic badge CSS class for the current status value.
     * Example: 'badge-light-warning' for 'pending'.
     */
    public function statusBadgeClass(): string
    {
        $color = config('global.data.demande_statuses.' . $this->status . '.color', 'secondary');

        return 'badge-light-' . $color;
    }
}
