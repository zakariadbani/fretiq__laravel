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
     * Polymorphic tracking pixel table. One row per tracked message; accumulates open counts.
     * No FK on trackable_type/trackable_id (polymorphic). Orphans pruned at the job layer.
     *
     * Unique constraints:
     *   token — globally unique pixel token for webhook/pixel lookup
     *
     * Indexes:
     *   (trackable_type, trackable_id) — reverse lookup: all events for a given trackable
     */
    public function up(): void
    {
        Schema::create('email_tracking_events', function (Blueprint $table) {
            $table->id();

            // Polymorphic — no FK; orphan pruning handled at the application layer
            $table->string('trackable_type', 255);
            $table->unsignedBigInteger('trackable_id');

            // token: ascii char(64) — compact, index-safe pixel token
            $table->char('token', 64)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->unique();

            // event: creating event kind — sent|opened|clicked|bounced|...
            $table->string('event', 12)->default('sent');

            // Human vs machine open discrimination
            $table->timestamp('first_human_open_at')->nullable();
            $table->timestamp('last_human_open_at')->nullable();
            $table->integer('human_open_count')->default(0);
            $table->integer('machine_open_count')->default(0);
            $table->string('last_opened_ip', 45)->nullable();

            $table->timestamps();

            // ── Index ─────────────────────────────────────────────────────────────
            $table->index(['trackable_type', 'trackable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_tracking_events');
    }
};
