<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 24);
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prospect_criteria_id')->nullable()->constrained('prospect_criteria')->nullOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->char('source_fingerprint', 64)->nullable()->unique();
            $table->string('quality_preset', 16)->default('balanced');
            $table->json('quality_settings')->nullable();
            $table->json('source_options')->nullable();
            $table->json('source_cursor')->nullable();
            $table->json('estimate')->nullable();
            $table->longText('recovery_audit')->nullable();
            $table->timestamp('cost_confirmed_at')->nullable();

            foreach ([
                'total_items',
                'processed_items',
                'review_items',
                'failed_items',
                'promoted_companies',
                'candidate_contacts',
            ] as $column) {
                $table->unsignedInteger($column)->default(0);
            }

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_batches');
    }
};
