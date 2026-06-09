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
     * Passwords are intentionally simple (hash of 'password') for local / smoke-test use only.
     *
     * Users created:
     *   admin@fretiq.test      → role: superadmin
     *   commercial@fretiq.test → role: commercial
     */
    public function run(): void
    {
        // ── Superadmin ─────────────────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@fretiq.test'],
            [
                'name'              => 'Admin',
                'password'          => Hash::make('password'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->assignRole('superadmin');

        // ── Commercial ────────────────────────────────────────────────────────
        $commercial = User::firstOrCreate(
            ['email' => 'commercial@fretiq.test'],
            [
                'name'              => 'Commercial',
                'password'          => Hash::make('password'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );
        $commercial->assignRole('commercial');
    }
}
