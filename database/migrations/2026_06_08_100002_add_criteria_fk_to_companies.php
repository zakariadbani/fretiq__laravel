<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `criteria_id` column + index already exist on the companies table
     * (added in 2026_06_06_100001). This migration adds only the FK constraint
     * now that prospect_criteria is created (2026_06_08_100001 runs first).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->foreign('criteria_id')
                  ->references('id')
                  ->on('prospect_criteria')
                  ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['criteria_id']);
        });
    }
};
