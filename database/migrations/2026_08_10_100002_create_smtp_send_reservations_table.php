<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_send_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sender_identity_id')->constrained('sender_identities')->restrictOnDelete();
            // Keep the sender/day quota ledger after a campaign is removed.
            // The campaign controller blocks deletion once delivery may have
            // started; nullOnDelete also protects history from other delete paths.
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->dateTime('reserved_for');
            $table->string('status', 16)->default('reserved');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('provider_message_id', 191)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index(['sender_identity_id', 'status', 'reserved_for'], 'smtp_reservations_identity_status_time');
            $table->index(['campaign_id', 'status', 'reserved_for'], 'smtp_reservations_campaign_status_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_send_reservations');
    }
};
