<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop Campaign.status — is_active is the sole live/paused gate.
     *
     * Context: Campaign.status ('draft'/'active'/'done') was retired in favour of
     * is_active (boolean pause switch). The two flags drifted apart because the UI
     * status controls were removed while code paths still set status='active' via
     * different routes. All code now gates only on is_active.
     *
     * No data backfill: is_active already holds the truth. Campaigns currently
     * is_active=0 stay paused until the user toggles them Active. A blanket
     * is_active=true WHERE status='active' was explicitly rejected — it would
     * silently un-pause deliberately-paused campaigns (the old migration and the
     * datatable toggle leave paused rows at status='active', is_active=0, so
     * 'drifted' and 'intentionally paused' are indistinguishable).
     *
     * Historical status values are unrecoverable after this migration runs.
     *
     * DO NOT run without explicit user go: php artisan migrate
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Drop the composite index on (status, next_run_at) — must happen before
            // dropping the column, otherwise the DB engine will complain.
            $table->dropIndex(['status', 'next_run_at']);

            $table->dropColumn('status');

            // Replace with the new hot-path index on the sole live gate.
            $table->index(['schedule_type', 'is_active', 'next_run_at']);
        });
    }

    /**
     * Reverse the migration.
     *
     * Re-adds the status column with its original default and indexes.
     * NOTE: historical status values (draft/active/done per row) are
     * unrecoverable — all rows will show 'draft' after rollback.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Re-add status after send_window (original column order).
            $table->string('status', 12)->default('draft')->after('send_window');

            // Drop the is_active+next_run_at index added in up().
            $table->dropIndex(['schedule_type', 'is_active', 'next_run_at']);

            // Restore the original scheduler hot-path index.
            $table->index(['status', 'next_run_at']);
        });
    }
};
