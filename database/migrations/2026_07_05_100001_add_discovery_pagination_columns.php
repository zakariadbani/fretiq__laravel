<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds durable SerpAPI pagination state:
     *   prospect_criteria.discovery_cursors - per-query start cursor + rotation key.
     *   discovery_runs.candidates_snapshot  - append-only candidate list for retries.
     *
     * DO NOT RUN automatically - user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->json('discovery_cursors')->nullable()->after('ai_queries');
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->json('candidates_snapshot')->nullable()->after('error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->dropColumn('discovery_cursors');
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn('candidates_snapshot');
        });
    }
};
