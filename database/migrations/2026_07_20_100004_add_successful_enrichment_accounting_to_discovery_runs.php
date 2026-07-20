<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->unsignedInteger('successful_enrichments_target')
                ->default(0)
                ->after('contact_consumed');
            $table->unsignedInteger('successful_enrichments')
                ->default(0)
                ->after('successful_enrichments_target');
            $table->uuid('enrichment_batch_id')
                ->nullable()
                ->after('successful_enrichments')
                ->index();

            $table->unique(
                ['enrichment_batch_id', 'company_id'],
                'discovery_runs_enrichment_batch_company_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropUnique('discovery_runs_enrichment_batch_company_unique');
            $table->dropIndex(['enrichment_batch_id']);
            $table->dropColumn([
                'enrichment_batch_id',
                'successful_enrichments',
                'successful_enrichments_target',
            ]);
        });
    }
};
