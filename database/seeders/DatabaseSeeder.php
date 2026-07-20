<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: roles must exist before permissions sync,
     * permissions must exist before users are assigned roles.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            \Database\Seeders\Acl\RolesSeeder::class,
            \Database\Seeders\Acl\PermissionsSeeder::class,
            \Database\Seeders\UsersSeeder::class,
            \Database\Seeders\SettingsSeeder::class,
            \Database\Seeders\DefaultProspectionSeeder::class,
            \Database\Seeders\SequenceSeeder::class,
            \Database\Seeders\ProspectCriteriaSeeder::class,
            // Must run after ProspectCriteriaSeeder (reads the famille criteria ids)
            // and after DefaultProspectionSeeder (reads the mnejjar@tcl.ma sender).
            \Database\Seeders\TclFamilleSequenceSeeder::class,
            \Database\Seeders\PackagesSeeder::class,
        ]);

        if (app()->environment(['testing'])) {
            $this->call([\Database\Seeders\DemoCompaniesSeeder::class]);
        }
    }
}
