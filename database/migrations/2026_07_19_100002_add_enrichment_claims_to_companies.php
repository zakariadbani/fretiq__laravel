<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the durable owner and attempt timestamp used to admit Hunter calls.
     *
     * The claim is nullable and nulls on run deletion so company history remains
     * readable even when an old ledger row is removed. Legacy criteria values are
     * clamped in the same deployment that starts enforcing the hard 20-call cap.
     *
     * DO NOT RUN automatically; the operator runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('enrichment_attempted_at')
                ->nullable()
                ->after('enrichment_status')
                ->index();

            $table->foreignId('enrichment_claim_run_id')
                ->nullable()
                ->after('enrichment_attempted_at')
                ->constrained('discovery_runs')
                ->nullOnDelete();
        });

        DB::table('prospect_criteria')
            ->where('contact_limit', '>', 20)
            ->update(['contact_limit' => 20]);
    }

    public function down(): void
    {
        // The legacy contact_limit clamp is intentionally irreversible: the
        // previous values cannot be reconstructed safely during rollback.
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['enrichment_claim_run_id']);
            $table->dropIndex(['enrichment_attempted_at']);
            $table->dropColumn([
                'enrichment_claim_run_id',
                'enrichment_attempted_at',
            ]);
        });
    }
};
