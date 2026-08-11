<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prospect_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_batch_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 24);
            $table->string('operation', 48);
            $table->string('engine', 32)->nullable();
            $table->char('idempotency_key', 64);
            $table->string('status', 16)->default('reserved')->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('result_count')->default(0);
            $table->decimal('reserved_units', 12, 2)->default(0);
            $table->decimal('consumed_units', 12, 2)->default(0);
            $table->string('provider_request_id', 191)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'operation', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_calls');
    }
};
