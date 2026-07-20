<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('sequence_auto_enroll_enabled')
                ->default(false)
                ->index()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['sequence_auto_enroll_enabled']);
            $table->dropColumn('sequence_auto_enroll_enabled');
        });
    }
};
