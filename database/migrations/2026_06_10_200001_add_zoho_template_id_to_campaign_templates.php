<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the zoho_template_id column to campaign_templates so that
     * templates imported from Zoho CRM can be tracked and updated idempotently.
     *
     * DO NOT RUN without explicit user go (migration guardrail).
     */
    public function up(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->string('zoho_template_id', 100)
                ->nullable()
                ->unique()
                ->after('thumbnail_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->dropUnique(['zoho_template_id']);
            $table->dropColumn('zoho_template_id');
        });
    }
};
