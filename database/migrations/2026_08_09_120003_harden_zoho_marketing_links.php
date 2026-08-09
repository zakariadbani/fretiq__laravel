<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_marketing_links', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('invalidated_at')->nullable();
            $table->string('invalidation_reason', 64)->nullable();
            $table->unsignedInteger('validation_count')->default(1);
            $table->unsignedInteger('invalidation_count')->default(0);
            $table->index(['match_type', 'is_active'], 'zoho_marketing_links_active_evidence_index');
            $table->index('invalidated_at', 'zoho_marketing_links_invalidated_at_index');
        });

        DB::table('zoho_marketing_links')
            ->whereNull('validated_at')
            ->update(['validated_at' => DB::raw('COALESCE(matched_at, updated_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('zoho_marketing_links', function (Blueprint $table): void {
            $table->dropIndex('zoho_marketing_links_active_evidence_index');
            $table->dropIndex('zoho_marketing_links_invalidated_at_index');
            $table->dropColumn([
                'is_active',
                'validated_at',
                'invalidated_at',
                'invalidation_reason',
                'validation_count',
                'invalidation_count',
            ]);
        });
    }
};
