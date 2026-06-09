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
        Schema::create('segments', function (Blueprint $table) {
            $table->id();

            $table->string('name', 255);

            // scope: prospect | client | mixed
            $table->string('scope', 12)->default('client');

            // filter: JSON criteria for dynamic audience resolution (Sprint 2b+)
            $table->json('filter')->nullable();

            // last_built_at: datetime (not timestamp) — cursor/sync column
            $table->datetime('last_built_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
