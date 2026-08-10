<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sender_identities', function (Blueprint $table): void {
            $table->boolean('smtp_enabled')->default(false)->after('is_active');
            $table->string('smtp_host')->nullable()->after('smtp_enabled');
            $table->unsignedSmallInteger('smtp_port')->default(587)->after('smtp_host');
            $table->string('smtp_username')->nullable()->after('smtp_port');
            $table->text('smtp_password')->nullable()->after('smtp_username');
            $table->string('smtp_encryption', 8)->default('tls')->after('smtp_password');
            $table->unsignedSmallInteger('smtp_hourly_limit')->default(10)->after('smtp_encryption');
            $table->unsignedSmallInteger('smtp_daily_limit')->default(50)->after('smtp_hourly_limit');
        });
    }

    public function down(): void
    {
        Schema::table('sender_identities', function (Blueprint $table): void {
            $table->dropColumn([
                'smtp_enabled',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'smtp_hourly_limit',
                'smtp_daily_limit',
            ]);
        });
    }
};
