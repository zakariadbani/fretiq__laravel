<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sprint 3a — campaign foundation.
     * Each campaign_run is one scheduled dispatch execution of a campaign.
     * occurrence_key provides idempotency for recurring schedules.
     *
     * FK on-delete:
     *   campaign_id → campaigns CASCADE (run has no meaning without parent)
     *
     * Unique constraints:
     *   (campaign_id, occurrence_key) — one occurrence per cycle, idempotent dispatch
     *
     * Indexes:
     *   (status, run_at) — hot path: scheduler polling for runs to execute
     */
    public function up(): void
    {
        Schema::create('campaign_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('campaign_id');

            // occurrence_key: deterministic per cycle — idempotency token
            $table->string('occurrence_key', 64);

            // run_at: datetime (not timestamp) — avoids 2038 overflow + TZ issues
            $table->datetime('run_at');

            // status: scheduled | sending | sent | failed | canceled
            $table->string('status', 12)->default('scheduled');

            // Aggregate delivery stats (denormalised for fast dashboard reads)
            $table->integer('stats_sent')->default(0);
            $table->integer('stats_delivered')->default(0);
            $table->integer('stats_opened')->default(0);
            $table->integer('stats_clicked')->default(0);
            $table->integer('stats_bounced')->default(0);
            $table->integer('stats_unsubscribed')->default(0);
            $table->integer('stats_replied')->default(0);
            $table->integer('conversion_count')->default(0);

            // Zoho Campaigns keys (Phase 5+ — nullable in local driver)
            $table->string('zoho_list_key', 255)->nullable();
            $table->string('zoho_campaign_key', 255)->nullable();

            // driver_ref: external job/message reference for the active driver
            $table->string('driver_ref', 191)->nullable();

            // started_at / finished_at: timestamp is fine here (operational, not scheduled datetime)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // ── Foreign key ───────────────────────────────────────────────────────
            $table->foreign('campaign_id')
                  ->references('id')->on('campaigns')
                  ->cascadeOnDelete();

            // ── Unique constraint ─────────────────────────────────────────────────
            $table->unique(['campaign_id', 'occurrence_key']);

            // ── Indexes ───────────────────────────────────────────────────────────
            $table->index(['status', 'run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_runs');
    }
};
