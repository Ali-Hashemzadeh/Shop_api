<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogModuleSeeder;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogSampleDataSeeder;
use Modules\Identity\Infrastructure\Persistence\Seeders\IdentityModuleSeeder;
use Modules\Inventory\Infrastructure\Persistence\Seeders\InventoryPermissionsSeeder;
use Modules\Inventory\Infrastructure\Persistence\Seeders\InventorySampleDataSeeder;
use Modules\Media\Infrastructure\Persistence\Seeders\MediaModuleSeeder;
use Modules\Order\Infrastructure\Persistence\Seeders\OrderPermissionsSeeder;

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
            OrderPermissionsSeeder::class,
            CatalogSampleDataSeeder::class,
            InventorySampleDataSeeder::class,
        ]);
    }
}
