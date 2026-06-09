<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')
                  ->references('id')
                  ->on('companies')
                  ->onDelete('cascade');

            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            // email: ascii collation for index-byte safety under utf8mb4
            $table->string('email', 191)
                  ->charset('ascii')
                  ->collation('ascii_general_ci')
                  ->unique();

            $table->string('name', 255);
            $table->string('position', 120)->nullable();
            $table->string('phone', 50)->nullable();

            // source: discovered | zoho | manual
            $table->string('source', 16)->default('manual');

            // status: new | contacted | qualified | unqualified | converted
            $table->string('status', 16)->default('new');

            $table->string('zoho_contact_id', 100)->nullable();

            // Compliance fields
            // legal_basis: relationship | legitimate_interest | consent | unknown
            $table->string('legal_basis', 24)->default('unknown');
            $table->datetime('consent_at')->nullable();

            // email_kind: role | personal
            $table->string('email_kind', 12)->default('role');

            $table->string('source_url', 500)->nullable();
            $table->datetime('source_captured_at')->nullable();

            $table->string('email_verification_status', 16)->nullable();

            $table->softDeletes(); // deleted_at (GDPR erasure)
            $table->timestamps();

            // Hot-path indexes
            $table->index('company_id');
            $table->index('status');
            $table->index('assigned_to');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
