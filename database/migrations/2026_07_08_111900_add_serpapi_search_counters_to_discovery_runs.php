<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds explicit SerpAPI search-call counters so discovery_runs.consumed can
     * remain the candidate cursor while package daily/monthly credits track the
     * actual provider-call budget.
     *
     * DO NOT RUN automatically - user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('searches_reserved')
                ->nullable()
                ->after('credits_reserved');

            $table->unsignedInteger('searches_consumed')
                ->default(0)
                ->after('searches_reserved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn(['searches_reserved', 'searches_consumed']);
        });
    }
};
