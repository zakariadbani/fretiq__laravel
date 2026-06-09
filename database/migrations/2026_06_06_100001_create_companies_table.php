<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // criteria_id: FK to prospect_criteria deferred to P2b (table doesn't exist in Sprint 1)
            $table->unsignedBigInteger('criteria_id')->nullable()->index();

            // domain: ascii collation for index-byte safety under utf8mb4
            $table->string('domain', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->nullable()
                  ->unique();

            $table->string('name', 255);
            $table->string('sector', 100)->nullable();

            // country: ISO-3166-1 alpha-2, ascii collation
            $table->char('country', 2)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->nullable();

            $table->string('estimated_size', 20)->nullable();
            $table->string('phone', 50)->nullable();

            // relationship: prospect (cold) | client (warm)
            $table->string('relationship', 16)->default('prospect');

            // source: discovered | zoho | manual
            $table->string('source', 16)->default('manual');

            $table->json('enrichment_data')->nullable();
            $table->integer('ai_score')->nullable();
            $table->text('ai_explanation')->nullable();

            // qualification_status: pending | qualified | rejected
            $table->string('qualification_status', 16)->default('pending');

            $table->string('zoho_account_id', 100)->nullable();

            $table->timestamps();

            // NOTE: FK to prospect_criteria(id) ON DELETE SET NULL deferred to P2b migration
            // when prospect_criteria table is created. Only an index is added here.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
