<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds is_active boolean pause switch to campaigns.
     *
     * Lifecycle after this migration:
     *   draft → active → done
     *   is_active=false  — paused: scheduler freezes, planner stops projecting, badge « En pause ».
     *   is_active=true   — running normally.
     *
     * Data fixes applied in-place:
     *   - status='scheduled' → 'active'  (scheduled and active were behaviorally identical)
     *   - status='paused'    → 'active', is_active=false  (pause semantics moved to the column)
     *
     * NOTE: the status data-merge (scheduled→active, paused→active) is irreversible.
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
        });

        // Merge 'scheduled' → 'active' (they were identical in every code path).
        DB::table('campaigns')->where('status', 'scheduled')->update(['status' => 'active']);

        // Merge 'paused' → 'active' + set is_active=false (pause semantics moved to column).
        DB::table('campaigns')->where('status', 'paused')->update(['status' => 'active', 'is_active' => false]);
    }

    /**
     * Reverse the migrations.
     *
     * Drops the is_active column only. The status data-merge (scheduled→active, paused→active)
     * is irreversible — original 'scheduled' and 'paused' values cannot be recovered.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
