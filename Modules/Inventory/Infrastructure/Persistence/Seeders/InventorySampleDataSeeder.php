<?php

namespace Modules\Inventory\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
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

        // Seed initial stock through the real manager so every SKU gets an
        // accurate opening "restock" ledger entry (append-only audit history),
        // exactly as a production stock intake would. Guarded per-SKU so
        // re-running the seeder never double-counts.
        $inventory = app(InventoryManagerInterface::class);
        $seeded = 0;

        foreach ($skus as $sku) {
            if (InventoryStock::where('sku', $sku)->exists()) {
                continue;
            }

            $inventory->adjustStock(
                sku: $sku,
                quantityChange: 50,
                type: 'restock',
                refType: 'seed',
                refId: null,
                notes: 'Initial stock intake (seed).',
            );

            $seeded++;
        }

        $this->command->info("Inventory seeded: {$seeded} SKUs with 50 units each (opening ledger recorded).");
    }
}
