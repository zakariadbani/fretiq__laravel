<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_runs', function (Blueprint $table) {
            $table->timestamp('stats_synced_at')->nullable()->after('failure_reason');
            $table->text('stats_sync_error')->nullable()->after('stats_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_runs', function (Blueprint $table) {
            $table->dropColumn(['stats_synced_at', 'stats_sync_error']);
        });
    }
};
