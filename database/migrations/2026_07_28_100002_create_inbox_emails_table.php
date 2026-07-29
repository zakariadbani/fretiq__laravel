<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_identity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message_id', 191)->charset('ascii')->collation('ascii_general_ci')->unique();
            $table->string('in_reply_to', 500)->nullable();
            $table->string('from_email', 191);
            $table->string('from_name')->nullable();
            $table->string('to_email', 191)->nullable();
            $table->string('subject', 500)->nullable();
            $table->mediumText('body_text')->nullable();
            $table->mediumText('body_html')->nullable();
            $table->string('status', 20)->default('nouveau')->index();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_emails');
    }
};
