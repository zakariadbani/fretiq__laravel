<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * SHELL table for Sprint 1. Full population in Phase 3a.
     */
    public function up(): void
    {
        Schema::create('sender_identities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);

            // email + reply_to: ascii collation for index-byte safety under utf8mb4
            $table->string('email', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci');

            $table->string('reply_to', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->nullable();

            $table->text('signature_html')->nullable();

            // App enforces single-default logic at the service layer
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sender_identities');
    }
};
