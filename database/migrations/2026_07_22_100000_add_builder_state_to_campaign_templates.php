<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->json('builder_state')->nullable()->after('preview_text');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->dropColumn('builder_state');
        });
    }
};
