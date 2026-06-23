<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Chunk A — campaign → sequence wiring:
     *
     * 1. campaigns: add nullable sequence_id FK → sequences SET NULL on delete.
     *    Placed after sender_identity_id (additive, non-breaking, fully nullable).
     *    Also make template_id nullable (sequence campaigns carry templates per step,
     *    not at the campaign level — validated as required_unless:schedule_type,sequence).
     *
     * 2. sequence_enrollments: add unique(sequence_id, contact_id) to enforce
     *    net-new-only enrollment semantics at the DB level (race-proof backstop).
     *    The table is effectively empty pre-launch — zero backfill needed.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        // 1. Add sequence_id to campaigns and make template_id nullable
        Schema::table('campaigns', function (Blueprint $table) {
            // Make template_id nullable (sequence campaigns don't use a campaign-level template)
            $table->unsignedBigInteger('template_id')->nullable()->change();

            $table->unsignedBigInteger('sequence_id')
                  ->nullable()
                  ->after('sender_identity_id');

            $table->foreign('sequence_id')
                  ->references('id')
                  ->on('sequences')
                  ->nullOnDelete();
        });

        // 2. Add unique(sequence_id, contact_id) to sequence_enrollments.
        //    Pre-flight: delete duplicate (sequence_id, contact_id) rows that would
        //    prevent the unique index from being created. Keep the row with the
        //    lowest id (first-insert wins). A stray dev/test enrollment row could
        //    otherwise abort the migration.
        \Illuminate\Support\Facades\DB::statement("
            DELETE FROM sequence_enrollments
            WHERE id NOT IN (
                SELECT min_id FROM (
                    SELECT MIN(id) AS min_id
                    FROM sequence_enrollments
                    GROUP BY sequence_id, contact_id
                ) AS keep
            )
        ");

        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->unique(['sequence_id', 'contact_id'], 'se_sequence_contact_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            // The composite unique (sequence_id, contact_id) is the SOLE index backing the
            // sequence_id FK — InnoDB dropped the redundant auto FK-index when up() created
            // the composite. Give the FK a standalone index to fall back on, else dropping
            // the composite raises MySQL errno 1553.
            $table->index('sequence_id');
            $table->dropUnique('se_sequence_contact_unique');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign(['sequence_id']);
            $table->dropColumn('sequence_id');
            // Restore template_id to NOT NULL (reversing the nullable change)
            $table->unsignedBigInteger('template_id')->nullable(false)->change();
        });
    }
};
