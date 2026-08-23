<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds sequence_step_sends.clicked_at — SMTP sequence-step clicks were
     * previously invisible past EmailTrackingService::recordClick() (only
     * campaign_recipients.clicked_at existed), so they never reached the
     * segment engagement ("A cliqué") filter. Mirrors the existing
     * opened_at column: nullable, first-write-wins, set alongside opened_at
     * (a click implies an open).
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::table('sequence_step_sends', function (Blueprint $table) {
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequence_step_sends', function (Blueprint $table) {
            $table->dropColumn('clicked_at');
        });
    }
};
