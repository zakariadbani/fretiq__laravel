<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds AI natural-language targeting to prospect_criteria (ai_target/ai_exclude
     * descriptions + cached ai_queries), source-query tracking to companies
     * (discovery_query), and a rejected-candidate counter to discovery_runs
     * (excluded_count).
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->text('ai_target')->nullable()->after('name');
            $table->text('ai_exclude')->nullable();
            $table->json('ai_queries')->nullable();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->text('discovery_query')->nullable();
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('excluded_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->dropColumn(['ai_target', 'ai_exclude', 'ai_queries']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('discovery_query');
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn('excluded_count');
        });
    }
};
