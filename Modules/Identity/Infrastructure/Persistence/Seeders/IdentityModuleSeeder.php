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
            LocationSeeder::class,
            // Adjacency matrix for the Post shipping calculator — runs after
            // LocationSeeder so the provinces it references already exist.
            ProvinceNeighborSeeder::class,
        ]);

    }
}
