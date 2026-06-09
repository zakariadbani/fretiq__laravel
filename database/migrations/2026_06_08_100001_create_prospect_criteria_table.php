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
        Schema::create('prospect_criteria', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            // Discovery filter arrays — each stored as JSON
            $table->json('sectors')->nullable();
            $table->json('countries')->nullable();
            $table->json('company_sizes')->nullable();
            $table->json('target_positions')->nullable();

            // daily_limit: max companies to discover per day per criteria set
            $table->integer('daily_limit')->default(20);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prospect_criteria');
    }
};
