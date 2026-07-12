<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscoveryRun extends Model
{
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
        'started_at'                => 'datetime',
        'finished_at'               => 'datetime',
        'companies_count'           => 'integer',
        'new_companies_count'       => 'integer',
        'contacts_count'            => 'integer',
        'skipped_count'             => 'integer',
        'low_score_count'           => 'integer',
        'credits_reserved'          => 'integer',
        'searches_reserved'         => 'integer',
        'searches_consumed'         => 'integer',
        'consumed'                  => 'integer',
        'contact_credits_reserved'  => 'integer',
        'contact_consumed'          => 'integer',
        'excluded_count'            => 'integer',
        'quota_date'                => 'date',
        'candidates_snapshot'       => 'array',
    ];

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
     * Whether the run appears stale (in-flight but no heartbeat for too long).
     *
     * Two thresholds, matched to the job lifecycle:
     *   pending  → job was never picked up (worker likely down); flag after 60 s
     *              on created_at. Short threshold because no work has started yet.
     *   running  → job is legitimately running up to its $timeout = 300 s; only
     *              flag after 360 s (> timeout) which matches the lock expireAfter(360)
     *              margin. Reference: started_at (falling back to created_at).
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
            $threshold = 60;
        } elseif ($this->status === 'running') {
            // Job is executing; allow the full job timeout (300 s) plus a buffer
            // equal to the unique-lock expireAfter margin (360 s total).
            $ref = $this->started_at ?? $this->created_at;
            $threshold = 360;
        } else {
            return false;
        }

        if ($ref === null || $ref->isFuture()) {
            return false;
        }

        return $ref->diffInSeconds(now()) > $threshold;
    }
}
