<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds per-criteria discovery automation config to prospect_criteria:
     * auto_run/run_at_hour (daily scheduler on/off + hour, Europe/Paris),
     * contact_limit (per-run enrichment cap), min_score_enrich + auto_enrich
     * (per-criteria overrides of the global decouverte.* settings).
     *
     * DO NOT RUN automatically, user runs php artisan migrate explicitly.
     */
    public function up(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->boolean('auto_run')->default(false)->after('daily_limit');
            $table->unsignedTinyInteger('run_at_hour')->nullable()->after('daily_limit');
            $table->unsignedInteger('contact_limit')->nullable()->after('daily_limit');
            $table->unsignedTinyInteger('min_score_enrich')->nullable()->after('daily_limit');
            $table->boolean('auto_enrich')->nullable()->after('daily_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospect_criteria', function (Blueprint $table) {
            $table->dropColumn(['auto_run', 'run_at_hour', 'contact_limit', 'min_score_enrich', 'auto_enrich']);
        });
    }
};
