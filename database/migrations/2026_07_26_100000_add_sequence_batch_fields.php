<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_runs', function (Blueprint $table): void {
            $table->foreignId('sequence_step_id')->nullable()->after('campaign_id')->constrained('sequence_steps')->nullOnDelete();
            $table->string('failure_reason', 500)->nullable()->after('driver_ref');
        });
        Schema::table('sequence_step_sends', function (Blueprint $table): void {
            $table->foreignId('campaign_run_id')->nullable()->after('enrollment_id')->constrained('campaign_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sequence_step_sends', fn (Blueprint $table) => $table->dropConstrainedForeignId('campaign_run_id'));
        Schema::table('campaign_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sequence_step_id');
            $table->dropColumn('failure_reason');
        });
    }
};
