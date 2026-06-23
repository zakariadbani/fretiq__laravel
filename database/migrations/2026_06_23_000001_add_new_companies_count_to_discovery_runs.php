<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds new_companies_count to discovery_runs to track fresh inserts separately
     * from companies_count (which counts every candidate processed — insert OR update).
     *
     * Nullable intent:
     *   NULL  = pre-feature run (unknown split between inserts and updates)
     *   0+    = known: zero or more new inserts in this run
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('new_companies_count')->nullable()->after('companies_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropColumn('new_companies_count');
        });
    }
};
