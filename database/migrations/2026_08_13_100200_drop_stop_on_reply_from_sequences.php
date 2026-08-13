<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sequences', function (Blueprint $table): void {
            $table->dropColumn('stop_on_reply');
        });
    }

    public function down(): void
    {
        Schema::table('sequences', function (Blueprint $table): void {
            $table->boolean('stop_on_reply')->default(true)->after('is_active');
        });
    }
};
