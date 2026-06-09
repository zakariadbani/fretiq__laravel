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
        Schema::create('zoho_tokens', function (Blueprint $table) {
            $table->id();

            // service: crm | campaigns
            $table->string('service', 16);

            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            // expires_at: datetime (not timestamp) — sync/cursor column
            $table->datetime('expires_at')->nullable();

            $table->timestamps();

            $table->unique('service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zoho_tokens');
    }
};
