<?php

namespace Database\Seeders\Acl;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    /**
     * Seed the application's roles.
     *
     * Uses firstOrCreate so the seeder is idempotent and safe to re-run.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'superadmin', 'guard_name' => 'web'],
            ['name' => 'admin',      'guard_name' => 'web'],
            ['name' => 'commercial', 'guard_name' => 'web'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name'], 'guard_name' => $role['guard_name']]
            );
        }
    }
}
