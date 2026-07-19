<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds companies.enrichment_status — the per-company audit trail of WHY a
     * discovered company ended up with (or without) contacts. NULL means the
     * enrichment decision was never recorded (legacy rows, manual creations).
     *
     * Values are the App\Models\Company::ENRICHMENT_* constants.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('enrichment_status', 32)->nullable()->after('enrichment_data');
            $table->index('enrichment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['enrichment_status']);
            $table->dropColumn('enrichment_status');
        });
    }
};
