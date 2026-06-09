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
     * One row per contact per campaign_run. Tracks delivery lifecycle per recipient.
     *
     * FK on-delete:
     *   campaign_run_id → campaign_runs CASCADE (recipient row follows run)
     *   contact_id      → contacts      CASCADE (GDPR erasure cascades)
     *
     * Unique constraints:
     *   (campaign_run_id, contact_id)  — one record per contact per run
     *   provider_message_id            — global unique for dedup on webhook callbacks
     *
     * Indexes:
     *   (campaign_run_id, status) — hot path: bulk status update during dispatch
     *   contact_id                — reverse lookup: all runs a contact participated in
     */
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('campaign_run_id');
            $table->unsignedBigInteger('contact_id');

            // status: queued|sent|delivered|opened|clicked|bounced|replied|unsubscribed|skipped
            $table->string('status', 16)->default('queued');

            $table->string('skip_reason', 100)->nullable();

            // provider_message_id: ascii for index-byte safety; UNIQUE for webhook dedup
            $table->string('provider_message_id', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->nullable()
                  ->unique();

            $table->string('bounce_reason', 255)->nullable();

            // Delivery timeline — timestamp is fine (operational event time)
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('replied_at')->nullable();

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────────
            $table->foreign('campaign_run_id')
                  ->references('id')->on('campaign_runs')
                  ->cascadeOnDelete();

            $table->foreign('contact_id')
                  ->references('id')->on('contacts')
                  ->cascadeOnDelete();

            // ── Unique constraints ────────────────────────────────────────────────
            $table->unique(['campaign_run_id', 'contact_id']);

            // ── Indexes ───────────────────────────────────────────────────────────
            $table->index(['campaign_run_id', 'status']);
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
