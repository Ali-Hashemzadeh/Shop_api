<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Analytics\Infrastructure\Persistence\Seeders\AnalyticsPermissionsSeeder;
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
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\AiPromptSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ReviewSourceSeeder;
use Modules\Promotion\Infrastructure\Persistence\Seeders\PromotionPermissionsSeeder;
use Modules\Promotion\Infrastructure\Persistence\Seeders\PromotionSampleDataSeeder;
use Modules\Review\Infrastructure\Persistence\Seeders\ReviewPermissionsSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\PostTarifffSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentPermissionsSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentSampleDataSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentScheduleSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShippingParameterSeeder;
use Modules\Ticket\Infrastructure\Persistence\Seeders\TicketCategorySeeder;
use Modules\Ticket\Infrastructure\Persistence\Seeders\TicketPermissionsSeeder;

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
            // Dynamic Post shipping engine data (idempotent, additive). Neighbours are
            // seeded inside IdentityModuleSeeder; these two hold the tariffs + tunables.
            ShippingParameterSeeder::class,
            PostTarifffSeeder::class,
            NotificationPermissionsSeeder::class,
            PromotionPermissionsSeeder::class,
            AnalyticsPermissionsSeeder::class,
            CatalogSampleDataSeeder::class,
            // Runs after the catalog demo data: promotion targets are loose
            // references to real product/category ids, so those rows must exist first.
            PromotionSampleDataSeeder::class,
            InventorySampleDataSeeder::class,
            // Working periods + generated sessions must exist before orders are seeded:
            // the local-delivery demo orders book a real, bookable slot at "checkout".
            ShipmentScheduleSeeder::class,
            OrderSampleDataSeeder::class,
            PaymentSampleDataSeeder::class,
            // Runs last — activates a shipment per paid order and drives it to its state.
            ShipmentSampleDataSeeder::class,
            ReviewPermissionsSeeder::class,
            TicketPermissionsSeeder::class,
            TicketCategorySeeder::class,
            // AI product reviews: admin-only capability, the registered external
            // sources, and the two-stage prompt templates.
            ProductReviewAIPermissionsSeeder::class,
            ReviewSourceSeeder::class,
            AiPromptSeeder::class,
        ]);
    }
}
