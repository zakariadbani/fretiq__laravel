<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->timestamp('email_verification_checked_at')
                ->nullable()
                ->after('email_verification_status');
            $table->string('email_verification_source', 32)
                ->nullable()
                ->after('email_verification_checked_at');
            $table->index('email_verification_status');
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->string('bounce_type', 16)->nullable()->after('bounce_reason');
            $table->index(
                ['contact_id', 'bounce_type', 'bounced_at'],
                'recipient_contact_bounce_window_idx',
            );
        });

        Schema::table('sequence_step_sends', function (Blueprint $table): void {
            $table->string('bounce_type', 16)->nullable()->after('status');
            $table->string('bounce_reason', 500)->nullable()->after('bounce_type');
            $table->timestamp('bounced_at')->nullable()->after('bounce_reason');
            $table->index(
                ['bounce_type', 'bounced_at'],
                'sequence_send_bounce_window_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('sequence_step_sends', function (Blueprint $table): void {
            $table->dropIndex('sequence_send_bounce_window_idx');
            $table->dropColumn(['bounce_type', 'bounce_reason', 'bounced_at']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table): void {
            $table->dropIndex('recipient_contact_bounce_window_idx');
            $table->dropColumn('bounce_type');
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['email_verification_status']);
            $table->dropColumn([
                'email_verification_checked_at',
                'email_verification_source',
            ]);
        });
    }
};
