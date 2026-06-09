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
     * Global suppression list. Survives contact deletion (email is kept, contact_id nulled).
     *
     * FK on-delete:
     *   contact_id → contacts SET NULL (email survives; suppression must outlive contact for GDPR)
     *
     * Unique constraints:
     *   email — one suppression record per address
     */
    public function up(): void
    {
        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();

            // email: ascii collation for index-byte safety; UNIQUE for fast pre-send check
            $table->string('email', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->unique();

            // contact_id: nullable; SET NULL on contact delete so the suppression survives GDPR erasure
            $table->unsignedBigInteger('contact_id')->nullable();

            // reason: hard_bounce | unsubscribe | manual | spam | complaint
            $table->string('reason', 24)->default('manual');

            // source: campaign | sequence | import | manual
            $table->string('source', 16)->default('manual');

            $table->timestamps();

            // ── Foreign key ───────────────────────────────────────────────────────
            $table->foreign('contact_id')
                  ->references('id')->on('contacts')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppressions');
    }
};
