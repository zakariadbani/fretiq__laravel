<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the discovery_runs table to persist one row per pipeline execution
     * triggered for a ProspectCriteria. Mirrors the campaign_runs precedent.
     *
     * FK on-delete:
     *   prospect_criteria_id → prospect_criteria CASCADE
     *
     * Indexes:
     *   status (column index) — status polling
     *   (prospect_criteria_id, id) — composite for "latest run per criteria"
     */
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('prospect_criteria_id');

            // status: pending | running | completed | failed
            $table->string('status', 12)->default('pending')->index();

            // throughput counters for this run
            $table->integer('companies_count')->default(0);
            $table->integer('contacts_count')->default(0);
            $table->integer('skipped_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            // ── Foreign key ───────────────────────────────────────────────────────
            $table->foreign('prospect_criteria_id')
                  ->references('id')->on('prospect_criteria')
                  ->cascadeOnDelete();

            // ── Composite index ───────────────────────────────────────────────────
            $table->index(['prospect_criteria_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
