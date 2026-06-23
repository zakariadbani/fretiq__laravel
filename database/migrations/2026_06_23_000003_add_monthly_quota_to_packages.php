<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a monthly quota layer to packages:
     *
     *   monthly_credits         — monthly cap on the company/discovery meter.
     *                             Null = no monthly cap (unlimited; current behavior preserved).
     *                             Placed after daily_credits so both daily columns are
     *                             grouped together in the schema.
     *
     *   monthly_contact_credits — monthly cap on the contact/enrichment meter.
     *                             Null = no monthly cap. Placed after daily_contact_credits.
     *
     *   quota_anchor_date       — anchor from which monthly windows roll forward.
     *                             Null → fall back to 1st of the calendar month.
     *                             Validated past-or-today on write (before_or_equal:today)
     *                             to prevent signed-diff edge-cases in period math.
     *
     * No backfill — null values preserve the current unlimited behavior for all existing
     * packages without any operator intervention.
     *
     * quota_date index note (finding #9):
     *   discovery_runs.quota_date was indexed in migration 2026_06_10_400003 via ->index().
     *   That creates a standard btree index which MySQL / SQLite can use for both equality
     *   (= ?) and range (>= ? AND < ?) scans — the new usedInPeriod() range queries are
     *   sargable on the existing index. No additional index is added here.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('monthly_credits')
                  ->nullable()
                  ->after('daily_credits');

            $table->unsignedInteger('monthly_contact_credits')
                  ->nullable()
                  ->after('daily_contact_credits');

            $table->date('quota_anchor_date')
                  ->nullable()
                  ->after('monthly_contact_credits');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_credits',
                'monthly_contact_credits',
                'quota_anchor_date',
            ]);
        });
    }
};
