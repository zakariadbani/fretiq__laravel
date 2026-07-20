<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_company_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_run_id')
                ->nullable()
                ->constrained('campaign_runs')
                ->nullOnDelete();
            $table->string('status', 16)->default('claimed');
            $table->unsignedInteger('attempts')->default(1);
            $table->text('last_error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'company_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->foreignId('company_dispatch_id')
                ->nullable()
                ->after('campaign_run_id')
                ->constrained('campaign_company_dispatches')
                ->cascadeOnDelete();
            $table->unique(
                ['company_dispatch_id', 'contact_id'],
                'campaign_recipient_dispatch_contact_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropForeign(['company_dispatch_id']);
            $table->dropUnique('campaign_recipient_dispatch_contact_unique');
            $table->dropColumn('company_dispatch_id');
        });

        Schema::dropIfExists('campaign_company_dispatches');
    }
};
