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
     * Audit log of every individual step send attempt for an enrollment.
     * One row per (enrollment, step_no) — idempotency guard on dispatch.
     *
     * FK on-delete:
     *   enrollment_id → sequence_enrollments CASCADE (send record follows enrollment lifecycle)
     *
     * Unique constraint:
     *   (enrollment_id, step_no) — one send record per step per enrollment
     *
     * Engine: InnoDB.
     */
    public function up(): void
    {
        Schema::create('sequence_step_sends', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('enrollment_id');
            $table->integer('step_no');

            // provider_message_id: ascii for index-byte safety; nullable until actually sent
            $table->string('provider_message_id', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->nullable();

            // status: queued|sent|opened|skipped
            $table->string('status', 16)->default('queued');

            // Delivery timeline — timestamps are fine (operational event times)
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────────
            $table->foreign('enrollment_id')
                  ->references('id')->on('sequence_enrollments')
                  ->cascadeOnDelete();

            // ── Unique constraints ────────────────────────────────────────────────
            $table->unique(['enrollment_id', 'step_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequence_step_sends');
    }
};
