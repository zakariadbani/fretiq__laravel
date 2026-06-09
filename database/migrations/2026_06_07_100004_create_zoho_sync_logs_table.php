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
        Schema::create('zoho_sync_logs', function (Blueprint $table) {
            $table->id();

            // module: Accounts | Contacts
            $table->string('module', 64);

            // synced_at: datetime (not timestamp) — sync cursor column
            $table->datetime('synced_at');

            $table->integer('records_synced')->default(0);

            // status: success | partial | error
            $table->string('status', 16)->default('success');

            $table->text('error')->nullable();

            $table->integer('duration_ms')->nullable();

            $table->timestamps();

            // Composite index for log queries filtered by module + time
            $table->index(['module', 'synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zoho_sync_logs');
    }
};
