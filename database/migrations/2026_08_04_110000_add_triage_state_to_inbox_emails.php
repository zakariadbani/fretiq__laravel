<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_emails', function (Blueprint $table): void {
            $table->string('triage_action', 32)->nullable()->after('status')->index();
            $table->foreignId('sequence_step_send_id')->nullable()->after('campaign_recipient_id')->constrained('sequence_step_sends')->nullOnDelete();
            $table->foreignId('demande_id')->nullable()->after('sequence_step_send_id')->constrained()->nullOnDelete();
            $table->foreignId('triaged_by')->nullable()->after('demande_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('inbox_emails', 'sequence_step_send_id')) {
            Schema::table('inbox_emails', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('sequence_step_send_id');
            });
        }

        Schema::table('inbox_emails', function (Blueprint $table): void {
            $table->dropForeign(['demande_id']);
            $table->dropForeign(['triaged_by']);
            $table->dropColumn(['triage_action', 'demande_id', 'triaged_by']);
        });
    }
};
