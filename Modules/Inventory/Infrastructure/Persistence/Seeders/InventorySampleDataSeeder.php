<?php

namespace Modules\Inventory\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;

class InventorySampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $skus = ProductVariant::pluck('sku');

        if ($skus->isEmpty()) {
            $this->command->warn('No product variants found — run CatalogSampleDataSeeder first.');

            return;
        }

        foreach ($skus as $sku) {
            InventoryStock::firstOrCreate(
                ['sku' => $sku],
                ['quantity' => 50, 'reserved_quantity' => 0],
            );
        }

        $this->command->info("Inventory seeded: {$skus->count()} SKUs with 50 units each.");
    }
}
