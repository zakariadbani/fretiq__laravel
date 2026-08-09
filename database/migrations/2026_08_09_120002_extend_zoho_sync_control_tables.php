<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_sync_checkpoints', function (Blueprint $table): void {
            $table->string('cursor_page_token', 1024)->nullable()->change();
            $table->string('submodule', 64)->default('')->after('module');
            $table->string('sync_mode', 24)->default('delta')->after('submodule');
            $table->char('page_query_fingerprint', 64)->nullable()->after('sync_mode');
            $table->string('cursor_zoho_id', 100)->nullable()->after('cursor_modified_time');
            $table->string('page_last_zoho_id', 100)->nullable()->after('cursor_zoho_id');
            $table->dateTime('cursor_at')->nullable()->after('cursor_zoho_id');
            $table->string('reconcile_cursor_zoho_id', 100)->nullable()->after('cursor_at');
            $table->string('reconcile_correlation_id', 100)->nullable()->after('reconcile_cursor_zoho_id');
            $table->dateTime('reconcile_started_at')->nullable()->after('reconcile_correlation_id');
            $table->dateTime('page_token_expires_at')->nullable()->after('cursor_page_token');
            $table->string('lease_owner', 100)->nullable()->after('status');
            $table->dateTime('lease_expires_at')->nullable()->after('lease_owner');
            $table->dateTime('heartbeat_at')->nullable()->after('lease_expires_at');
            $table->unsignedInteger('retry_count')->default(0)->after('heartbeat_at');
            $table->dateTime('completed_at')->nullable()->after('retry_count');
            $table->json('counters')->nullable()->after('completed_at');
            $table->string('correlation_id', 100)->nullable()->after('counters');
            $table->unsignedBigInteger('sync_batch_id')->nullable()->after('correlation_id')->index();
            $table->unsignedInteger('generation')->default(0)->after('sync_batch_id');
            $table->dateTime('delivery_retry_deadline_at')->nullable()->after('generation');
        });

        Schema::table('zoho_sync_checkpoints', function (Blueprint $table): void {
            $table->dropUnique('zoho_sync_checkpoints_module_unique');
            $table->unique(['module', 'submodule'], 'zoho_sync_checkpoints_module_submodule_unique');
            $table->index(['status', 'lease_expires_at'], 'zoho_sync_checkpoints_lease_index');
        });

        Schema::table('zoho_sync_logs', function (Blueprint $table): void {
            $table->string('submodule', 64)->default('')->after('module');
            $table->string('mode', 24)->default('delta')->after('submodule');
            $table->unsignedBigInteger('sync_batch_id')->nullable()->after('mode')->index();
            $table->string('correlation_id', 100)->nullable()->after('sync_batch_id')->index();
            $table->integer('records_seen')->default(0)->after('records_synced');
            $table->integer('records_created')->default(0)->after('records_seen');
            $table->integer('records_updated')->default(0)->after('records_created');
            $table->integer('records_unchanged')->default(0)->after('records_updated');
            $table->integer('records_deleted')->default(0)->after('records_unchanged');
            $table->integer('records_quarantined')->default(0)->after('records_deleted');
            $table->string('cursor_zoho_id', 100)->nullable()->after('duration_ms');
            $table->dateTime('cursor_at')->nullable()->after('cursor_zoho_id');
            $table->integer('api_requests')->default(0)->after('cursor_at');
            $table->integer('api_credits')->nullable()->after('api_requests');
            $table->json('telemetry')->nullable()->after('api_credits');
        });

        Schema::table('zoho_sync_batches', function (Blueprint $table): void {
            $table->string('post_reconciliation_status', 24)->nullable()->after('error_summary')->index();
            $table->string('post_reconciliation_lease_owner', 100)->nullable()->after('post_reconciliation_status');
            $table->dateTime('post_reconciliation_lease_expires_at')->nullable()->after('post_reconciliation_lease_owner');
            $table->unsignedInteger('post_reconciliation_attempts')->default(0)->after('post_reconciliation_lease_expires_at');
            $table->string('post_reconciliation_error', 1000)->nullable()->after('post_reconciliation_attempts');
            $table->dateTime('post_reconciliation_started_at')->nullable()->after('post_reconciliation_error');
            $table->dateTime('post_reconciliation_completed_at')->nullable()->after('post_reconciliation_started_at');
            $table->dateTime('post_reconciliation_retry_not_before')->nullable()->after('post_reconciliation_completed_at');
            $table->dateTime('post_reconciliation_retry_deadline_at')->nullable()->after('post_reconciliation_retry_not_before');
        });
    }

    public function down(): void
    {
        Schema::table('zoho_sync_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'submodule', 'mode', 'sync_batch_id', 'correlation_id', 'records_seen', 'records_created',
                'records_updated', 'records_unchanged', 'records_deleted', 'records_quarantined',
                'cursor_zoho_id', 'cursor_at', 'api_requests', 'api_credits', 'telemetry',
            ]);
        });

        Schema::table('zoho_sync_checkpoints', function (Blueprint $table): void {
            $table->string('cursor_page_token', 255)->nullable()->change();
            $table->dropIndex('zoho_sync_checkpoints_lease_index');
            $table->dropUnique('zoho_sync_checkpoints_module_submodule_unique');
            $table->unique('module');
            $table->dropColumn([
                'submodule', 'sync_mode', 'page_query_fingerprint', 'cursor_zoho_id', 'page_last_zoho_id', 'cursor_at',
                'reconcile_cursor_zoho_id', 'reconcile_correlation_id', 'reconcile_started_at', 'page_token_expires_at',
                'lease_owner', 'lease_expires_at', 'heartbeat_at', 'retry_count', 'completed_at',
                'counters', 'correlation_id', 'sync_batch_id', 'generation', 'delivery_retry_deadline_at',
            ]);
        });
        Schema::table('zoho_sync_batches', function (Blueprint $table): void {
            $table->dropColumn(['post_reconciliation_status', 'post_reconciliation_lease_owner', 'post_reconciliation_lease_expires_at', 'post_reconciliation_attempts', 'post_reconciliation_error', 'post_reconciliation_started_at', 'post_reconciliation_completed_at', 'post_reconciliation_retry_not_before', 'post_reconciliation_retry_deadline_at']);
        });
    }
};
