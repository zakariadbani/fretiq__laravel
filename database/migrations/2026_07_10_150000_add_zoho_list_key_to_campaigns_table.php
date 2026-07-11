<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the actual stable Zoho list key owned by one Fretiq campaign.
     *
     * Nullable supports existing campaigns: their list is ensured once on the
     * next explicit preparation action. The unique index prevents one external
     * Zoho list from being linked to multiple Fretiq campaigns.
     *
     * DO NOT RUN automatically — this migration requires explicit user approval.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('zoho_list_key', 191)->nullable()->unique()->after('driver');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropUnique(['zoho_list_key']);
            $table->dropColumn('zoho_list_key');
        });
    }
};
