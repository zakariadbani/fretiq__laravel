<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_contact_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_batch_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 191)->charset('ascii')->collation('ascii_general_ci');
            $table->string('normalized_email', 191)->charset('ascii')->collation('ascii_general_ci');
            $table->string('name')->nullable();
            $table->string('position', 120)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('source', 32);
            $table->string('email_kind', 12)->default('role');
            $table->string('verification_status', 16)->nullable();
            $table->timestamp('verification_checked_at')->nullable();
            $table->string('verification_source', 32)->nullable();
            $table->string('decision', 16)->default('pending')->index();
            $table->string('decision_reason', 64)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['prospect_batch_id', 'normalized_email'], 'pcc_batch_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_contact_candidates');
    }
};
