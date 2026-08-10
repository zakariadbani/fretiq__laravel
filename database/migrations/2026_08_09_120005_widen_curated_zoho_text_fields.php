<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zoho_leads', function (Blueprint $table): void {
            $table->string('industry', 255)->nullable()->change();
        });

        Schema::table('zoho_accounts', function (Blueprint $table): void {
            $table->string('industry', 255)->nullable()->change();
        });

        Schema::table('zoho_quotes', function (Blueprint $table): void {
            $table->string('dimensions', 255)->nullable()->change();
            $table->string('free_time', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: narrowing these columns could truncate
        // verified Zoho values that are valid at the widened 255-char limit.
    }
};
