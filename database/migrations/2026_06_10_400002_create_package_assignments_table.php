<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates package_assignments — the active package is always the latest row.
     * History is preserved automatically (who assigned what, when).
     *
     * FK styles (matching project convention from discovery_runs migration):
     *   package_id  → packages         cascadeOnDelete
     *   assigned_by → users            nullOnDelete (nullable — seeded row has no user)
     *
     * Seeds the first assignment pointing at the "Illimité" package created in
     * the previous migration. assigned_by = null because no user exists yet when
     * this migration runs on a fresh install.
     *
     * DO NOT RUN automatically — user runs `php artisan migrate` explicitly.
     */
    public function up(): void
    {
        Schema::create('package_assignments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('package_id');
            // nullable: the seeded first-row has no admin user to attribute it to
            $table->unsignedBigInteger('assigned_by')->nullable();

            $table->timestamps();

            // ── Foreign keys ──────────────────────────────────────────────────
            $table->foreign('package_id')
                  ->references('id')->on('packages')
                  ->cascadeOnDelete();

            $table->foreign('assigned_by')
                  ->references('id')->on('users')
                  ->nullOnDelete();
        });

        // ── Seed the initial assignment ───────────────────────────────────────
        // Find the Illimité package seeded by the previous migration.
        $packageId = DB::table('packages')
            ->where('name', 'Illimité')
            ->value('id');

        if ($packageId) {
            $now = now();
            DB::table('package_assignments')->insert([
                'package_id'  => $packageId,
                'assigned_by' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_assignments');
    }
};
