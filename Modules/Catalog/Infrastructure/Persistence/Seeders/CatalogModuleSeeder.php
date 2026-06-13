<?php

namespace Modules\Catalog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;

class CatalogModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CatalogPermissionsSeeder::class,
        ]);
    }
}
