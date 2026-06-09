<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zoho_sync_checkpoints', function (Blueprint $table) {
            $table->id();

            // module: Accounts | Contacts
            $table->string('module', 64);

            // cursor_modified_time: datetime (not timestamp) — sync cursor column
            $table->datetime('cursor_modified_time')->nullable();

            $table->string('cursor_page_token', 255)->nullable();

            // status: idle | running | success | partial | error
            $table->string('status', 24)->default('idle');

            $table->timestamps();

            $table->unique('module');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zoho_sync_checkpoints');
    }
};
