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
     * A sequence is a named multi-step drip campaign. Steps and enrollments hang off it.
     *
     * FK on-delete: none (root table; referenced by sequence_steps and sequence_enrollments).
     *
     * Engine: InnoDB (Laravel default).
     */
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();

            $table->string('name', 255);

            // is_active: soft-disable without deleting steps/enrollments
            $table->boolean('is_active')->default(true);

            // stop_on_reply: pause/stop enrollment when a reply is detected
            $table->boolean('stop_on_reply')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
