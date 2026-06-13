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
     * Adds scoring gate counters, run type, and optional company_id to discovery_runs.
     *
     *   low_score_count          — candidates skipped by the scoring gate (scored but
     *                              below min_score_enrich). Persisted incrementally.
     *
     *   type                     — 'discovery' (default, scheduled/manual criteria run)
     *                              or 'manual' (future single-company enrichment trigger).
     *                              Indexed for filtered queries / history views.
     *
     *   company_id               — optional FK to companies; used by type='manual' runs.
     *                              Nullable; nullOnDelete so company deletion does not
     *                              cascade-delete run history.
     *
     * prospect_criteria_id is made nullable to support type='manual' rows that are not
     * tied to a ProspectCriteria (single-company enrichment). Existing rows are unaffected.
     *
     * SAFE nullable recipe for prospect_criteria_id:
     *   1. dropForeign  (by constraint name — see below)
     *   2. change() to nullable
     *   3. re-add foreign cascadeOnDelete with explicit name
     *
     * Constraint name used by the create migration:
     *   discovery_runs_prospect_criteria_id_foreign  (Laravel default convention)
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            // 1. Add low_score_count after skipped_count
            $table->unsignedInteger('low_score_count')
                  ->default(0)
                  ->after('skipped_count');

            // 2. Add type column after prospect_criteria_id
            $table->string('type', 20)
                  ->default('discovery')
                  ->after('prospect_criteria_id')
                  ->index();

            // 3. Add company_id after type
            $table->foreignId('company_id')
                  ->nullable()
                  ->after('type')
                  ->constrained('companies')
                  ->nullOnDelete();
        });

        // 4. Make prospect_criteria_id nullable — SAFE recipe:
        //    dropForeign → change → re-add foreign
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropForeign('discovery_runs_prospect_criteria_id_foreign');
            $table->unsignedBigInteger('prospect_criteria_id')->nullable()->change();
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->foreign('prospect_criteria_id', 'discovery_runs_prospect_criteria_id_foreign')
                  ->references('id')->on('prospect_criteria')
                  ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: deleting type='manual' rows is LOSSY — manual run history is irrecoverable.
     */
    public function down(): void
    {
        // Remove manual rows first (lossy — manual run history lost on rollback)
        DB::table('discovery_runs')->where('type', 'manual')->delete();

        // Re-tighten prospect_criteria_id to NOT NULL — SAFE recipe in reverse
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropForeign('discovery_runs_prospect_criteria_id_foreign');
            $table->unsignedBigInteger('prospect_criteria_id')->nullable(false)->change();
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->foreign('prospect_criteria_id', 'discovery_runs_prospect_criteria_id_foreign')
                  ->references('id')->on('prospect_criteria')
                  ->cascadeOnDelete();
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->dropIndex(['type']);
            $table->dropColumn('type');
            $table->dropColumn('low_score_count');
        });
    }
};
