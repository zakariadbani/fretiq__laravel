<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('delivery_channel', 16)->nullable()->after('driver');
            $table->unsignedSmallInteger('smtp_daily_email_limit')->nullable()->after('delivery_channel');
            $table->timestamp('delivery_started_at')->nullable()->after('smtp_daily_email_limit');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn(['delivery_channel', 'smtp_daily_email_limit', 'delivery_started_at']);
        });
    }
};
