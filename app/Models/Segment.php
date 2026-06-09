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
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'   => 'required|string|max:255',
            'scope'  => 'required|in:' . implode(',', array_keys(config('global.data.segment_scopes', []))),
            'filter' => 'nullable|array',
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Returns a simple contact count filtered by this segment's scope.
     *
     * scope 'client'   → contacts belonging to companies with relationship='client'
     * scope 'prospect' → contacts belonging to companies with relationship='prospect'
     * scope 'mixed'    → all contacts
     *
     * Dynamic filter resolution (Sprint 2b+) is not yet implemented; the try/catch
     * guards against any future resolution failure without surfacing an exception to
     * the caller.
     *
     * @return int
     */
    public function contactsCount(): int
    {
        try {
            $query = Contact::query()->join('companies', 'contacts.company_id', '=', 'companies.id');

            if ($this->scope === 'client') {
                $query->where('companies.relationship', 'client');
            } elseif ($this->scope === 'prospect') {
                $query->where('companies.relationship', 'prospect');
            }
            // 'mixed' → no relationship filter

            return $query->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
