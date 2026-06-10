<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds four quota-tracking columns to discovery_runs:
     *
     *   credits_reserved  — reserved at dispatch = min(daily_limit, remaining).
     *                       Closes the double-spend hole for dispatched-but-not-
     *                       executed runs (they haven't incremented consumed yet).
     *
     *   consumed          — incremented immediately before each Hunter domainSearch
     *                       call. Persisted per-call so a killed worker does not
     *                       lose the tally. A retry's budget = credits_reserved − consumed.
     *
     *   quota_date        — the solde day this run spends from, fixed at dispatch.
     *                       Indexed for the sargable WHERE quota_date = ? query.
     *                       Pinning to dispatch day closes the midnight-straddle hole.
     *
     *   package_assignment_id — snapshot of the assignment that authorised the run;
     *                           nullable (existing rows and unlimited runs leave it null).
     *                           nullOnDelete keeps the ledger row even if the assignment
     *                           is somehow deleted.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('credits_reserved')
                  ->default(0)
                  ->after('skipped_count');

            $table->unsignedInteger('consumed')
                  ->default(0)
                  ->after('credits_reserved');

            $table->date('quota_date')
                  ->nullable()
                  ->after('consumed')
                  ->index();

            $table->unsignedBigInteger('package_assignment_id')
                  ->nullable()
                  ->after('quota_date');

            $table->foreign('package_assignment_id')
                  ->references('id')->on('package_assignments')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropForeign(['package_assignment_id']);
            $table->dropIndex(['quota_date']);
            $table->dropColumn([
                'credits_reserved',
                'consumed',
                'quota_date',
                'package_assignment_id',
            ]);
        });
    }
};
