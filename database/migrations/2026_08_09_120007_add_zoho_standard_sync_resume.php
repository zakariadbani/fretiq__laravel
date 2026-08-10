<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_sync_batches', function (Blueprint $table): void {
            $table->dateTime('paused_at')->nullable()->index();
            $table->dateTime('resumed_at')->nullable();
            $table->unsignedInteger('resume_count')->default(0);
            $table->string('pause_reason', 255)->nullable();
            $table->json('resume_metadata')->nullable();
        });

        Schema::create('zoho_standard_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sync_batch_id')->index();
            $table->string('module', 64)->index();
            $table->string('submodule', 64)->default('')->index();
            $table->string('correlation_id', 100)->index();
            $table->string('mode', 24)->index();
            $table->string('status', 24)->default('enumerating')->index();
            $table->char('query_fingerprint', 64);
            $table->json('query_params');
            $table->dateTime('watermark_at')->index();
            $table->dateTime('since_at')->nullable()->index();
            $table->string('page_token', 1024)->nullable();
            $table->dateTime('page_token_expires_at')->nullable();
            $table->unsignedInteger('enumeration_restart_count')->default(0);
            $table->json('counters')->nullable();
            $table->dateTime('enumerated_at')->nullable()->index();
            $table->dateTime('completed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['sync_batch_id', 'module', 'submodule'], 'zoho_standard_sync_runs_batch_module_submodule_unique');
        });

        Schema::create('zoho_standard_sync_work_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('zoho_standard_sync_run_id')->index();
            $table->string('zoho_id', 100);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedBigInteger('delivery_generation')->default(0);
            $table->string('lease_owner', 100)->nullable();
            $table->dateTime('lease_expires_at')->nullable()->index();
            $table->string('outcome', 32)->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_unchanged')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->dateTime('processed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['zoho_standard_sync_run_id', 'zoho_id'], 'zoho_standard_sync_work_items_run_zoho_unique');
            $table->index(['zoho_standard_sync_run_id', 'status', 'zoho_id'], 'zoho_standard_sync_work_items_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_standard_sync_work_items');
        Schema::dropIfExists('zoho_standard_sync_runs');

        Schema::table('zoho_sync_batches', function (Blueprint $table): void {
            $table->dropColumn(['paused_at', 'resumed_at', 'resume_count', 'pause_reason', 'resume_metadata']);
        });
    }
};
