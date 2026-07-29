<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sender_identities', function (Blueprint $table) {
            $table->string('imap_host')->nullable()->after('is_active');
            $table->unsignedSmallInteger('imap_port')->default(993)->after('imap_host');
            $table->string('imap_username')->nullable()->after('imap_port');
            $table->text('imap_password')->nullable()->after('imap_username');
            $table->string('imap_encryption', 16)->default('ssl')->after('imap_password');
            $table->boolean('imap_validate_cert')->default(true)->after('imap_encryption');
            $table->boolean('imap_enabled')->default(false)->after('imap_validate_cert');
            $table->timestamp('last_polled_at')->nullable()->after('imap_enabled');
            $table->string('last_poll_error', 500)->nullable()->after('last_polled_at');
            $table->unsignedTinyInteger('consecutive_poll_failures')->default(0)->after('last_poll_error');
        });
    }

    public function down(): void
    {
        Schema::table('sender_identities', function (Blueprint $table) {
            $table->dropColumn([
                'imap_host',
                'imap_port',
                'imap_username',
                'imap_password',
                'imap_encryption',
                'imap_validate_cert',
                'imap_enabled',
                'last_polled_at',
                'last_poll_error',
                'consecutive_poll_failures',
            ]);
        });
    }
};
