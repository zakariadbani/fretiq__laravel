<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageAssignment extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'package_id',
        'assigned_by',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    /**
     * The package this assignment points at.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * The user who created this assignment (nullable — system/seeded rows have no user).
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    // ── Scopes / Helpers ───────────────────────────────────────────────────────

    /**
     * Return the currently active assignment with its package eager-loaded,
     * or null when no assignment exists (= unlimited by convention).
     *
     * "Active" = the latest row in the table. History is preserved implicitly.
     */
    public static function latestActive(): ?self
    {
        return static::with('package')->orderByDesc('id')->first();
    }
}
