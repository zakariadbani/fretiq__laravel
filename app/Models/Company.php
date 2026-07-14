<?php

namespace App\Models;

use App\Models\Traits\Validator;
use App\Support\ConfigEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, Validator;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'companies';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'criteria_id',
        'domain',
        'name',
        'sector',
        'country',
        'estimated_size',
        'phone',
        'relationship',
        'source',
        'enrichment_data',
        'ai_score',
        'ai_explanation',
        'qualification_status',
        'is_active',
        'zoho_account_id',
        'discovery_query',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'enrichment_data' => 'array',
        'ai_score'        => 'integer',
        'is_active'       => 'boolean',
    ];

    /**
     * Coalesce NOT NULL columns to their schema defaults when an empty form
     * submit (ConvertEmptyStringsToNull) would otherwise pass null and trip
     * the column's NOT NULL constraint.
     */
    protected static function booted(): void
    {
        static::saving(function (self $company): void {
            $company->relationship         ??= 'prospect';
            $company->source               ??= 'manual';
            $company->qualification_status ??= 'pending';
            $company->is_active           ??= true;
        });

        // Hide rejected (AI-excluded competitor) companies from every read path
        // by default — Companies CRUD, dashboards, contact/segment pickers, audiences.
        // Use withRejected() to opt back in (audit view, dedup/upsert lookups).
        static::addGlobalScope('notRejected', function (Builder $builder): void {
            $builder->where(function (Builder $query): void {
                $query->where('companies.qualification_status', '!=', 'rejected')
                    ->orWhereNull('companies.qualification_status');
            });
        });
    }

    /**
     * Escape hatch for the notRejected global scope — removes it so rejected
     * companies are included again. Required on the upsert domain-lookup path
     * (else a re-discovered rejected domain isn't found and Company::create()
     * throws on the unique domain constraint) and on the results audit toggle.
     */
    public function scopeWithRejected(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notRejected');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->withRejected()->where('companies.qualification_status', 'rejected');
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The prospect criteria set this company was discovered under.
     */
    public function criteria(): BelongsTo
    {
        return $this->belongsTo(ProspectCriteria::class, 'criteria_id');
    }

    /**
     * A company has many contacts.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * Validation rules for the model.
     * The Validator trait calls this via validator() / validate().
     * Unique rules exclude the current model's id on update automatically because
     * $this->id is null on create and set on update.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name'                 => 'required|string|max:255',
            'domain'               => 'nullable|string|max:191|unique:companies,domain,' . $this->id,
            'sector'               => 'nullable|string|max:100',
            'country'              => 'nullable|string|size:2',
            'estimated_size'       => 'nullable|string|max:20',
            'phone'                => 'nullable|string|max:50',
            'relationship'         => 'nullable|' . ConfigEnum::in('company_relationships'),
            'source'               => 'nullable|' . ConfigEnum::in('company_sources'),
            'qualification_status' => 'nullable|' . ConfigEnum::in('company_qualification_statuses'),
            'is_active'            => 'nullable|boolean',
            'ai_score'             => 'nullable|integer|between:0,100',
            'zoho_account_id'      => 'nullable|string|max:100',
        ];
    }

    // ── Accessors / Helpers ────────────────────────────────────────────────────

    /**
     * Returns the Metronic badge CSS class for the current relationship value.
     * Example: 'badge-light-primary' for 'prospect'.
     */
    public function relationshipBadgeClass(): string
    {
        $color = config('global.data.company_relationships.' . $this->relationship . '.color', 'secondary');

        return 'badge-light-' . $color;
    }

    public function hasSocialDomain(): bool
    {
        return ! empty($this->domain)
            && app(\App\Services\Discovery\CompanyDiscoveryService::class)->isBlockedDomain($this->domain);
    }
}
