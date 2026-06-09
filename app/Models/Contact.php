<?php

namespace App\Models;

use App\Models\Traits\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use Validator, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'contacts';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'company_id',
        'assigned_to',
        'email',
        'name',
        'position',
        'phone',
        'source',
        'status',
        'zoho_contact_id',
        'legal_basis',
        'consent_at',
        'email_kind',
        'source_url',
        'source_captured_at',
        'email_verification_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'consent_at'         => 'datetime',
        'source_captured_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The company this contact belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The staff user assigned to this contact (nullable).
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     * Unique email rule excludes the current model's id on update via $this->id.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'company_id'               => 'required|integer|exists:companies,id',
            'assigned_to'              => 'nullable|integer|exists:users,id',
            'email'                    => 'required|email|max:191|unique:contacts,email,' . $this->id,
            'name'                     => 'required|string|max:255',
            'position'                 => 'nullable|string|max:120',
            'phone'                    => 'nullable|string|max:50',
            'source'                   => 'nullable|in:' . implode(',', array_keys(config('global.data.contact_sources', []))),
            'status'                   => 'nullable|in:' . implode(',', array_keys(config('global.data.contact_statuses', []))),
            'legal_basis'              => 'nullable|in:' . implode(',', array_keys(config('global.data.contact_legal_bases', []))),
            'email_kind'               => 'nullable|in:' . implode(',', array_keys(config('global.data.contact_email_kinds', []))),
            'consent_at'               => 'nullable|date',
            'source_url'               => 'nullable|string|max:500',
            'source_captured_at'       => 'nullable|date',
            'email_verification_status'=> 'nullable|string|max:16',
            'zoho_contact_id'          => 'nullable|string|max:100',
        ];
    }

    // ── Accessors / Helpers ────────────────────────────────────────────────────

    /**
     * Returns the Metronic badge CSS class for the current status value.
     * Example: 'badge-light-success' for 'qualified'.
     */
    public function statusBadgeClass(): string
    {
        $color = config('global.data.contact_statuses.' . $this->status . '.color', 'secondary');

        return 'badge-light-' . $color;
    }
}
