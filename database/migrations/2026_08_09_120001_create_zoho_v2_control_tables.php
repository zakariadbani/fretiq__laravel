<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoho_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('correlation_id', 100)->unique();
            $table->string('mode', 24)->index();
            $table->string('trigger', 24)->default('manual')->index();
            $table->string('status', 24)->default('queued')->index();
            $table->string('requested_by_type', 32)->nullable();
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->json('modules')->nullable();
            $table->json('counters')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->dateTime('requested_at')->nullable()->index();
            $table->dateTime('scheduled_for')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('zoho_sync_failures', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sync_batch_id')->nullable()->index();
            $table->string('module', 64)->index();
            $table->string('submodule', 64)->default('')->index();
            $table->string('zoho_id', 100)->nullable()->index();
            $table->string('failure_kind', 32)->index();
            $table->char('failure_key', 64)->unique();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->text('error_summary');
            $table->json('context')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('retry_after')->nullable()->index();
            $table->dateTime('resolved_at')->nullable()->index();
            $table->timestamps();
            $table->index(['resolved_at', 'retry_after'], 'zoho_sync_failures_retry_queue_index');
        });

        Schema::create('zoho_field_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('module', 64);
            $table->string('submodule', 64)->default('');
            $table->char('schema_hash', 64);
            $table->json('fields');
            $table->json('layouts')->nullable();
            $table->json('picklists')->nullable();
            $table->json('related_lists')->nullable();
            $table->boolean('is_current')->default(true)->index();
            $table->string('drift_state', 24)->default('verified')->index();
            $table->dateTime('verified_at')->nullable()->index();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['module', 'submodule', 'schema_hash']);
        });

        Schema::create('zoho_user_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_user_id', 100)->unique();
            $table->foreignId('fretiq_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('zoho_normalized_email', 320)->nullable()->index();
            $table->string('fretiq_normalized_email', 320)->nullable()->index();
            $table->string('match_method', 32)->nullable();
            $table->boolean('is_confirmed')->default(false)->index();
            $table->boolean('is_override')->default(false)->index();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('zoho_marketing_links', function (Blueprint $table): void {
            $table->id();
            $table->string('zoho_module', 64)->index();
            $table->string('zoho_record_id', 100)->index();
            $table->string('fretiq_entity_type', 64)->index();
            $table->unsignedBigInteger('fretiq_entity_id')->index();
            $table->string('match_type', 32)->index();
            $table->string('match_key_hash', 64)->index();
            $table->json('audit_metadata')->nullable();
            $table->dateTime('matched_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['zoho_module', 'zoho_record_id', 'fretiq_entity_type', 'fretiq_entity_id', 'match_type'], 'zoho_marketing_links_match_unique');
        });

        // Bulk exports only ever contain record identifiers.  Keeping the job
        // and its work queue separately makes an interrupted export resumable
        // without storing an export payload (which can contain CRM PII).
        Schema::create('zoho_bulk_read_jobs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('sync_batch_id')->index();
            $table->string('module', 64)->index();
            $table->string('submodule', 64)->default('')->index();
            $table->string('zoho_job_id', 100)->nullable()->unique();
            $table->string('correlation_id', 100)->index();
            $table->string('status', 24)->default('queued')->index();
            $table->string('page_key', 64);
            $table->string('page_token', 512)->nullable();
            $table->char('schema_hash', 64)->nullable();
            $table->string('module_lease_owner', 100)->nullable();
            $table->string('lease_owner', 100)->nullable();
            $table->unsignedBigInteger('delivery_generation')->default(0);
            $table->dateTime('lease_expires_at')->nullable()->index();
            $table->dateTime('heartbeat_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('retry_after')->nullable()->index();
            $table->json('counters')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->string('terminalization_reason', 32)->nullable();
            $table->json('terminalization_context')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('watermark_at')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['sync_batch_id', 'module', 'page_key'], 'zoho_bulk_read_jobs_batch_module_page_unique');
        });

        Schema::create('zoho_sync_work_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('zoho_bulk_read_job_id')->index();
            $table->string('module', 64)->index();
            $table->string('zoho_id', 100);
            $table->string('status', 24)->default('queued')->index();
            $table->string('lease_owner', 100)->nullable();
            $table->dateTime('lease_expires_at')->nullable()->index();
            $table->dateTime('heartbeat_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('retry_after')->nullable()->index();
            $table->dateTime('processed_at')->nullable()->index();
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_unchanged')->default(0);
            $table->unsignedInteger('api_requests')->default(0);
            $table->string('correlation_id', 100)->index();
            $table->timestamps();
            $table->unique(['zoho_bulk_read_job_id', 'zoho_id'], 'zoho_sync_work_items_job_zoho_unique');
            $table->index(['module', 'status', 'lease_expires_at'], 'zoho_sync_work_items_claim_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zoho_sync_work_items');
        Schema::dropIfExists('zoho_bulk_read_jobs');
        Schema::dropIfExists('zoho_marketing_links');
        Schema::dropIfExists('zoho_user_mappings');
        Schema::dropIfExists('zoho_field_manifests');
        Schema::dropIfExists('zoho_sync_failures');
        Schema::dropIfExists('zoho_sync_batches');
    }
};
