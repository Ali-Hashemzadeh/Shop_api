<?php

namespace Modules\Identity\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;

class IdentityModuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DefaultUsersSeeder::class,
            LocationSeeder::class
        ]);

    }
}
