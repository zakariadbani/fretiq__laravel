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
     * A campaign links a segment, a template and a sender identity and defines when/how to run.
     *
     * FK on-delete:
     *   segment_id          → segments           RESTRICT (protect audience definition)
     *   template_id         → campaign_templates  RESTRICT (protect template in use)
     *   sender_identity_id  → sender_identities   RESTRICT (protect identity in use)
     *
     * Indexes:
     *   (status, next_run_at) — hot path: scheduler picks next active campaign to dispatch
     *   (scheduled_at)        — hot path: one-shot scheduling lookup
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('segment_id');
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('sender_identity_id');

            $table->string('name', 255);

            // subject: optional override of template.subject
            $table->string('subject', 255)->nullable();

            // schedule_type: one_shot | recurring | sequence
            $table->string('schedule_type', 12)->default('one_shot');

            // scheduled_at / next_run_at: datetime (not timestamp) — avoids 2038 overflow + TZ issues
            $table->datetime('scheduled_at')->nullable();
            $table->json('recurrence')->nullable();
            $table->datetime('next_run_at')->nullable();

            $table->string('timezone', 64)->default('Europe/Paris');
            $table->json('send_window')->nullable();

            // status: draft | scheduled | active | paused | done
            $table->string('status', 12)->default('draft');

            // driver: local | zoho
            $table->string('driver', 8)->default('local');

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────────
            $table->foreign('segment_id')
                  ->references('id')->on('segments')
                  ->restrictOnDelete();

            $table->foreign('template_id')
                  ->references('id')->on('campaign_templates')
                  ->restrictOnDelete();

            $table->foreign('sender_identity_id')
                  ->references('id')->on('sender_identities')
                  ->restrictOnDelete();

            // ── Indexes ───────────────────────────────────────────────────────────
            $table->index(['status', 'next_run_at']);
            $table->index('scheduled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
