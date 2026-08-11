<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_runs', function (Blueprint $table) {
            $table->foreignId('source_run_id')
                ->nullable()
                ->after('campaign_id')
                ->constrained('campaign_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_run_id');
        });
    }
};
