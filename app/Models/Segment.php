<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;

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
        'last_built_at' => 'datetime',
    ];

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
        $contactStatuses = implode(',', array_keys(config('global.data.contact_statuses', [])));

        return [
            'name'  => 'required|string|max:255',
            'scope' => 'required|in:' . implode(',', array_keys(config('global.data.segment_scopes', []))),

            // Top-level filter: optional array
            'filter'          => 'nullable|array',

            // filter.sector: optional array of up to 20 free-text sector strings
            'filter.sector'   => 'nullable|array|max:20',
            'filter.sector.*' => 'string|max:100',

            // filter.country: optional array of up to 20 valid ISO-3166-1 alpha-2 codes
            'filter.country'   => 'nullable|array|max:20',
            'filter.country.*' => 'string|size:2|in:' . $countryCodes,

            // filter.status: optional single contact-status value
            'filter.status'   => 'nullable|string|in:' . $contactStatuses,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Returns the true deliverable contact count for this segment.
     *
     * Delegates to SegmentService::resolveWithStats() so the count reflects
     * ALL compliance stages (scope + filter + suppression + cold gate +
     * email-kind + dedup) — identical to the count that resolve() would produce.
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
                ->resolveWithStats($this->scope, $this->filter ?? [])['final'];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('segments.contactsCount failed', ['segment_id' => $this->id, 'message' => $e->getMessage()]);
            return 0;
        }
    }
}
