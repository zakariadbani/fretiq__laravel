<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sprint 3b — automation depth.
     * Tracks one contact's progress through a sequence. current_step is the ordinal of the
     * last step sent (0 = not started). next_send_at drives the scheduler hot-path query.
     *
     * FK on-delete:
     *   sequence_id → sequences  CASCADE   (enrollment follows sequence lifecycle)
     *   contact_id  → contacts   CASCADE   (GDPR erasure cascades)
     *   campaign_id → campaigns  SET NULL  (attribution; nullable — sequence may run standalone)
     *
     * Indexes:
     *   (status, next_send_at) — hot path: scheduler picks next enrollment to dispatch
     *   contact_id             — reverse lookup: all sequences a contact is enrolled in
     *
     * Engine: InnoDB.
     */
    public function up(): void
    {
        Schema::create('sequence_enrollments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sequence_id');
            $table->unsignedBigInteger('contact_id');

            // campaign_id: optional attribution to a campaign that triggered enrollment
            $table->unsignedBigInteger('campaign_id')->nullable();

            // current_step: ordinal of last dispatched step (0 = none sent yet)
            $table->integer('current_step')->default(0);

            // status: active|paused|completed|stopped
            $table->string('status', 12)->default('active');

            // next_send_at: datetime (not timestamp) — avoids 2038 overflow + TZ issues
            $table->datetime('next_send_at')->nullable();

            // last_sent_at: timestamp is fine (operational event time)
            $table->timestamp('last_sent_at')->nullable();

            $table->string('stopped_reason', 100)->nullable();

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────────
            $table->foreign('sequence_id')
                  ->references('id')->on('sequences')
                  ->cascadeOnDelete();

            $table->foreign('contact_id')
                  ->references('id')->on('contacts')
                  ->cascadeOnDelete();

            $table->foreign('campaign_id')
                  ->references('id')->on('campaigns')
                  ->nullOnDelete();

            // ── Indexes ───────────────────────────────────────────────────────────
            $table->index(['status', 'next_send_at']);
            $table->index('contact_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequence_enrollments');
    }
};
