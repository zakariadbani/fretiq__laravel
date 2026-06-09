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
     * One row per step in a sequence. step_no is the 1-based ordinal; delay_days is the
     * number of days to wait after the previous step before sending this one.
     *
     * FK on-delete:
     *   sequence_id → sequences           CASCADE  (steps follow sequence lifecycle)
     *   template_id → campaign_templates  RESTRICT (protect template referenced by a step)
     *
     * Unique constraint:
     *   (sequence_id, step_no) — enforces ordinal uniqueness within a sequence
     *
     * Engine: InnoDB.
     */
    public function up(): void
    {
        Schema::create('sequence_steps', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sequence_id');
            $table->integer('step_no');

            // delay_days: days after previous step (0 = send immediately / same day as enrollment)
            $table->integer('delay_days')->default(0);

            $table->unsignedBigInteger('template_id');

            // subject: optional override of template.subject
            $table->string('subject', 255)->nullable();

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────────
            $table->foreign('sequence_id')
                  ->references('id')->on('sequences')
                  ->cascadeOnDelete();

            $table->foreign('template_id')
                  ->references('id')->on('campaign_templates')
                  ->restrictOnDelete();

            // ── Unique constraints ────────────────────────────────────────────────
            $table->unique(['sequence_id', 'step_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequence_steps');
    }
};
