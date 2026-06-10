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
     * Creates the packages table and seeds the default "Illimité" package
     * (daily_credits = null = unlimited) so that any fresh or existing install
     * behaves identically to today after migrate (no assignment at all = unlimited).
     *
     * Seeding is done here with DB::table() — NOT Eloquent — because models may
     * not be loadable mid-migrate. down() drops the table cleanly (FK from
     * package_assignments is dropped first in its own migration's down()).
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // null = unlimited; quota checks are skipped entirely when null
            $table->unsignedInteger('daily_credits')->nullable();

            // Display-only; no billing logic in v1
            $table->decimal('price_monthly', 10, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });

        // ── Seed the default "Illimité" package ───────────────────────────────
        // daily_credits = null → unlimited; all quota checks skipped.
        // Existing installs will behave identically to before this migration.
        $now = now();

        DB::table('packages')->insert([
            'name'          => 'Illimité',
            'daily_credits' => null,
            'price_monthly' => null,
            'is_active'     => true,
            'sort_order'    => 0,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * NOTE: package_assignments references packages with cascadeOnDelete, so
     * dropping packages will cascade. But because the FK is defined in the
     * package_assignments migration, that migration's down() must run first
     * (reverse order is enforced by Laravel's rollback stack).
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
