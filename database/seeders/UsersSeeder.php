<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Seed the application's admin and commercial users.
     *
     * Uses firstOrCreate by email so the seeder is idempotent and safe to re-run.
     * Seeded defaults: admin@fretiq.test / 'admin@fretiq@2026',
     * commercial@fretiq.test / 'commercial@fretiq@'. Weak by design — rotate the
     * prod superadmin before real use.
     *
     * Users created:
     *   admin@fretiq.test      → role: admin
     *   superadmin@fretiq.test      → role: superadmin
     *   commercial@fretiq.test → role: commercial
     */
    public function run(): void
    {
        // ── Superadmin ─────────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'superadmin@fretiq.test'],
            [
                'name'              => 'Super Admin',
                'password'          => Hash::make('superadmin@fretiq@2026'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('superadmin');

        // ── Admin ─────────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@fretiq.test'],
            [
                'name'              => 'Admin',
                'password'          => Hash::make('admin@fretiq@2026'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('admin');

        // ── Commercial ────────────────────────────────────────────────────────
        $commercial = User::firstOrCreate(
            ['email' => 'commercial@fretiq.test'],
            [
                'name'              => 'Commercial',
                'password'          => Hash::make('commercial@fretiq@'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $commercial->assignRole('commercial');
    }
}
