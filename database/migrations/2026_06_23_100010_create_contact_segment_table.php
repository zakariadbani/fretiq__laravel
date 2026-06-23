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
        Schema::create('contact_segment', function (Blueprint $table) {
            $table->id();

            $table->foreignId('segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();

            // include: force-add this contact to the resolved audience (bypasses scope+filter, still faces compliance).
            // exclude: unconditionally remove this contact after dedup (exclude always wins).
            $table->enum('mode', ['include', 'exclude']);

            $table->timestamps();

            // One pin per contact+segment pair; mode flip is an upsert, not a new row.
            $table->unique(['segment_id', 'contact_id']);

            // Secondary index for "all segments pinning this contact" queries.
            $table->index('contact_id');
        });

        // NOTE: Contact uses SoftDeletes — a contact soft-delete is an UPDATE (deleted_at set),
        // NOT a DELETE. The cascadeOnDelete above will NOT fire on soft-delete.
        // Pivot rows will linger after a contact is soft-deleted. This is harmless:
        //   - resolve() and resolveWithStats() call Contact::with/whereHas which apply the global
        //     SoftDeletes scope, so soft-deleted contacts are never hydrated into the audience.
        //   - includedContactIds() / excludedContactIds() go through pinnedContacts() (BelongsToMany),
        //     which also applies the Contact global scope.
        // If a contact is force-deleted (hard delete), the FK cascade fires and the pivot row is removed.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_segment');
    }
};
