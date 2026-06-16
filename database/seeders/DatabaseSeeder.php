<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogModuleSeeder;
use Modules\Identity\Infrastructure\Persistence\Seeders\IdentityModuleSeeder;
use Modules\Inventory\Infrastructure\Persistence\Seeders\InventoryPermissionsSeeder;
use Modules\Media\Infrastructure\Persistence\Seeders\MediaModuleSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            IdentityModuleSeeder::class,
            CatalogModuleSeeder::class,
            MediaModuleSeeder::class,
            InventoryPermissionsSeeder::class,
        ]);
    }
}
