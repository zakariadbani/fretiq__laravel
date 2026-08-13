<?php

namespace App\Models;

use App\Jobs\StartContactEmailVerificationJob;
use App\Services\Discovery\EmailVerificationSettings;
use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes, Validator;

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
        'zoho_contact_id',
        'email_kind',
        'source_url',
        'source_captured_at',
        'email_verification_status',
        'email_verification_checked_at',
        'email_verification_source',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'source_captured_at' => 'datetime',
        'email_verification_checked_at' => 'datetime',
    ];

    private bool $queueInitialEmailVerification = false;

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

    /**
     * Global normalized-email lookup, including GDPR tombstones. Promotion
     * uses this scope so a soft-deleted address can never be reassigned.
     */
    public function scopeWithNormalizedEmail(Builder $query, string $email): Builder
    {
        return $query->withTrashed()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))]);
    }

    // ── Lifecycle hooks ────────────────────────────────────────────────────────

    /**
     * Apply column defaults for nullable enum fields when the submitted value is
     * empty or null, so we never insert an explicit NULL into a NOT NULL column
     * that carries a sensible DB default.
     *
     * DB defaults: source='manual', email_kind='role'.
     */
    protected static function booted(): void
    {
        static::saving(function (Contact $contact) {
            $defaults = [
                'source' => 'manual',
                'email_kind' => 'role',
            ];
            foreach ($defaults as $col => $default) {
                $val = $contact->getAttribute($col);
                if ($val === null || $val === '') {
                    $contact->setAttribute($col, $default);
                }
            }

            $emailChanged = $contact->exists && $contact->isDirty('email');
            if ($emailChanged) {
                $contact->forceFill([
                    'email_verification_status' => null,
                    'email_verification_source' => null,
                    'email_verification_checked_at' => null,
                ]);
            }

            $hasImportedEvidence = filled($contact->email_verification_status)
                || filled($contact->email_verification_source)
                || $contact->email_verification_checked_at !== null;
            $contact->queueInitialEmailVerification = $emailChanged || (! $contact->exists && ! $hasImportedEvidence);
        });

        static::saved(function (Contact $contact): void {
            if (! $contact->queueInitialEmailVerification
                || ! app(EmailVerificationSettings::class)->enabled()) {
                return;
            }

            $contact->queueInitialEmailVerification = false;
            StartContactEmailVerificationJob::dispatch(
                (int) $contact->getKey(),
                hash('sha256', strtolower(trim((string) $contact->email))),
            )->afterCommit();
        });
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
            'company_id' => 'required|integer|exists:companies,id',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'email' => 'required|email|max:191|unique:contacts,email,'.$this->id,
            'name' => 'required|string|max:255',
            'position' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:50',
            'source' => 'nullable|'.ConfigEnum::in('contact_sources'),
            'email_kind' => 'nullable|'.ConfigEnum::in('contact_email_kinds'),
            'source_url' => 'nullable|string|max:500',
            'source_captured_at' => 'nullable|date',
            'email_verification_status' => 'nullable|string|max:16',
            'email_verification_checked_at' => 'nullable|date',
            'email_verification_source' => 'nullable|string|max:32',
            'zoho_contact_id' => 'nullable|string|max:100',
        ];
    }

    // ── Accessors / Helpers ────────────────────────────────────────────────────

    public function lifecycleState(): string
    {
        return app(\App\Services\Prospecting\ContactLifecycleService::class)->stateFor($this);
    }
}
