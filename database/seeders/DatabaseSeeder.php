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
use Modules\Notification\Infrastructure\Persistence\Seeders\NotificationPermissionsSeeder;
use Modules\Order\Infrastructure\Persistence\Seeders\OrderPermissionsSeeder;
use Modules\Order\Infrastructure\Persistence\Seeders\OrderSampleDataSeeder;
use Modules\Payment\Infrastructure\Persistence\Seeders\PaymentPermissionsSeeder;
use Modules\Payment\Infrastructure\Persistence\Seeders\PaymentSampleDataSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentPermissionsSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentSampleDataSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentScheduleSeeder;

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
            PaymentPermissionsSeeder::class,
            ShipmentPermissionsSeeder::class,
            NotificationPermissionsSeeder::class,
            CatalogSampleDataSeeder::class,
            InventorySampleDataSeeder::class,
            // Working periods + generated sessions must exist before orders are seeded:
            // the local-delivery demo orders book a real, bookable slot at "checkout".
            ShipmentScheduleSeeder::class,
            OrderSampleDataSeeder::class,
            PaymentSampleDataSeeder::class,
            // Runs last — activates a shipment per paid order and drives it to its state.
            ShipmentSampleDataSeeder::class,
        ]);
    }
}
