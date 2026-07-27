<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DiscoveryRun extends Model
{
    public const DISPATCH_FAILURE_MESSAGE = 'La découverte n’a pas pu être mise en file. Réessayez dans quelques instants.';

    public const UNEXPECTED_FAILURE_MESSAGE = 'La découverte a échoué après plusieurs tentatives. Consultez les journaux applicatifs puis relancez-la.';

    /** Daily runs may legitimately wait behind a long single-worker queue. */
    public const PENDING_STALE_AFTER_SECONDS = 86400;

    public const RUNNING_STALE_AFTER_SECONDS = 660;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'prospect_criteria_id',
        'type',
        'company_id',
        'status',
        'companies_count',
        'new_companies_count',
        'contacts_count',
        'skipped_count',
        'low_score_count',
        'credits_reserved',
        'searches_reserved',
        'searches_consumed',
        'consumed',
        'contact_credits_reserved',
        'contact_consumed',
        'successful_enrichments_target',
        'successful_enrichments',
        'enrichment_batch_id',
        'hunter_circuit_open',
        'excluded_count',
        'quota_date',
        'package_assignment_id',
        'started_at',
        'finished_at',
        'error',
        'candidates_snapshot',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'companies_count' => 'integer',
        'new_companies_count' => 'integer',
        'contacts_count' => 'integer',
        'skipped_count' => 'integer',
        'low_score_count' => 'integer',
        'credits_reserved' => 'integer',
        'searches_reserved' => 'integer',
        'searches_consumed' => 'integer',
        'consumed' => 'integer',
        'contact_credits_reserved' => 'integer',
        'contact_consumed' => 'integer',
        'successful_enrichments_target' => 'integer',
        'successful_enrichments' => 'integer',
        'hunter_circuit_open' => 'boolean',
        'excluded_count' => 'integer',
        'quota_date' => 'date',
        'candidates_snapshot' => 'array',
    ];

    /**
     * Keep the quota ledger key date-only on every supported database driver.
     * MySQL truncates a datetime assigned to a DATE column automatically, while
     * SQLite keeps the time component unless the model normalizes it first.
     */
    public function setQuotaDateAttribute(mixed $value): void
    {
        $this->attributes['quota_date'] = $value === null || $value === ''
            ? null
            : Carbon::parse($value)->toDateString();
    }

    /**
     * Mark a dispatch as failed only while the exact discovery run is still queued.
     * A queue transport can throw after accepting the payload, so a worker-owned or
     * already completed row must never be regressed by the caller's catch block.
     */
    public static function failPendingDispatch(int $runId, int $criteriaId): bool
    {
        return static::query()
            ->whereKey($runId)
            ->where('prospect_criteria_id', $criteriaId)
            ->where('type', 'discovery')
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'error' => self::DISPATCH_FAILURE_MESSAGE,
                'finished_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    // ── Relationships ──────────────────────────────────────────────────────────

    public function prospectCriteria(): BelongsTo
    {
        return $this->belongsTo(ProspectCriteria::class, 'prospect_criteria_id');
    }

    /**
     * The package assignment that authorised this run (snapshot at dispatch time).
     * Nullable — unlimited runs and rows pre-dating this migration leave it null.
     */
    public function packageAssignment(): BelongsTo
    {
        return $this->belongsTo(PackageAssignment::class);
    }

    /**
     * The company targeted by a type='manual' run.
     * Nullable — type='discovery' runs leave it null.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Company::class, 'company_id');
    }

    // ── State helpers ──────────────────────────────────────────────────────────

    /**
     * Refresh the observable heartbeat. False means this run is no longer 'running'
     * (another actor terminalized it) — never "the timestamp did not change".
     *
     * MySQL reports CHANGED rows, not matched rows, so an `updated_at = now()` write
     * landing in the same second as the previous one returns 0 affected rows. Ownership
     * must therefore be re-asserted explicitly, not inferred from that count.
     */
    public static function touchHeartbeat(Builder $ownedQuery): bool
    {
        (clone $ownedQuery)->where('status', 'running')->update(['updated_at' => now()]);

        return (clone $ownedQuery)->where('status', 'running')->exists();
    }

    /**
     * Whether the run appears stale (in-flight but no heartbeat for too long).
     *
     * Two thresholds, matched to the job lifecycle:
     *   pending  → job was never picked up; flag after 24 h on created_at. A
     *              one-minute threshold is unsafe when a single database worker
     *              is occupied by another 540-second discovery job.
     *   running  → flag after 660 s without a heartbeat, beyond the 540-second
     *              job timeout and 570-second overlap-lock expiry. Reference:
     *              updated_at (legacy fallback to started_at, then created_at).
     *
     * Carbon 3 note: diffInSeconds() is SIGNED. To get a positive value for a
     * past timestamp, the PAST timestamp must be the receiver:
     *   $past->diffInSeconds(now())  →  positive
     *   now()->diffInSeconds($past)  →  negative  ← NEVER use this form
     */
    public function isStale(): bool
    {
        // Terminal or explicitly finished — never stale.
        if ($this->finished_at !== null) {
            return false;
        }

        if ($this->status === 'pending') {
            // Job was queued but never picked up. Warn quickly (worker-down signal).
            $ref = $this->created_at;
            $threshold = self::PENDING_STALE_AFTER_SECONDS;
        } elseif ($this->status === 'running') {
            // updated_at is refreshed after each resumable unit of work. The
            // started_at/created_at fallback only exists for legacy null rows.
            $ref = $this->updated_at ?? $this->started_at ?? $this->created_at;
            $threshold = self::RUNNING_STALE_AFTER_SECONDS;
        } else {
            return false;
        }

        if ($ref === null || $ref->isFuture()) {
            return false;
        }

        return $ref->diffInSeconds(now()) >= $threshold;
    }
}
