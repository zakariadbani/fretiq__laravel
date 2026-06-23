<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a parallel contact/enrichment meter alongside the existing
     * company/discovery meter (daily_credits / credits_reserved / consumed).
     *
     * New columns:
     *
     *   packages.daily_contact_credits       — daily cap for contact enrichment.
     *                                          Null = unlimited (same convention as
     *                                          daily_credits). Backfilled to match
     *                                          daily_credits so existing packages
     *                                          start with a symmetrical cap.
     *
     *   discovery_runs.contact_credits_reserved — reserved enrichment credits at
     *                                             dispatch time. Backfilled from
     *                                             credits_reserved.
     *
     *   discovery_runs.contact_consumed       — enrichment credits actually consumed.
     *
     * Backfill rationale for contact_consumed
     * ─────────────────────────────────────────
     * Rows whose quota_date is in the PAST already burned real company credits; we
     * copy an approximation (consumed − low_score_count, floor 0) as the contact
     * spend for historical reporting, but we deliberately do NOT pre-burn today's
     * or future dates. Setting today/future rows to 0 means the contact meter starts
     * fresh from the moment this migration runs, so operators cannot be surprised by
     * an immediate quota-exhausted error on the new meter for today's already-dispatched
     * runs. GREATEST(0, …) guards against negative values when low_score_count > consumed.
     *
     * quota_date uses app timezone (written by Carbon::today(config('app.timezone'))),
     * so we compute today in PHP and pass it as a parameter — never rely on MySQL
     * CURDATE() which operates in the DB server's TZ and can differ.
     */
    public function up(): void
    {
        // ── packages ──────────────────────────────────────────────────────────
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('daily_contact_credits')
                  ->nullable()
                  ->after('daily_credits');
        });

        // ── discovery_runs ────────────────────────────────────────────────────
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('contact_credits_reserved')
                  ->default(0)
                  ->after('consumed');

            $table->unsignedInteger('contact_consumed')
                  ->default(0)
                  ->after('contact_credits_reserved');
        });

        // ── Backfill ──────────────────────────────────────────────────────────

        // packages: copy daily_credits → daily_contact_credits (null copies as null).
        DB::statement('UPDATE packages SET daily_contact_credits = daily_credits');

        // discovery_runs: contact_credits_reserved mirrors credits_reserved.
        DB::statement('UPDATE discovery_runs SET contact_credits_reserved = credits_reserved');

        // discovery_runs: contact_consumed — today and future rows start at 0
        // (no pre-burn); past rows get an approximation capped at 0.
        $today = \Illuminate\Support\Carbon::today(config('app.timezone'))->toDateString();

        // Today, future, and NULL quota_date rows: start fresh, contact meter = 0.
        DB::update(
            'UPDATE discovery_runs SET contact_consumed = 0 WHERE quota_date >= ? OR quota_date IS NULL',
            [$today]
        );

        // Past rows: approximate contact spend = max(0, consumed - low_score_count).
        DB::update(
            'UPDATE discovery_runs
             SET contact_consumed = GREATEST(0, consumed - COALESCE(low_score_count, 0))
             WHERE quota_date < ?',
            [$today]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn(['contact_credits_reserved', 'contact_consumed']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('daily_contact_credits');
        });
    }
};
