<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->text('original_input');
            $table->string('company_name');
            $table->string('normalized_name');
            $table->char('country', 2)->nullable();
            $table->string('city', 120)->nullable();

            foreach (['provided_domain', 'selected_domain', 'registrable_domain'] as $column) {
                $table->string($column, 191)
                    ->charset('ascii')
                    ->collation('ascii_general_ci')
                    ->nullable()
                    ->index();
            }

            $table->json('domain_alternatives')->nullable();
            $table->unsignedTinyInteger('domain_confidence')->nullable();
            $table->string('domain_reason', 64)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->json('source_metadata')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['prospect_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_batch_items');
    }
};
