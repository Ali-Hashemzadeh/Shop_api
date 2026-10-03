<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Infrastructure\Persistence\Seeders\AnalyticsPermissionsSeeder;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogPermissionsSeeder;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Seeders\RolesAndPermissionsSeeder;
use Modules\Inventory\Infrastructure\Persistence\Seeders\InventoryPermissionsSeeder;
use Modules\Media\Infrastructure\Persistence\Seeders\MediaPermissionsSeeder;
use Modules\Notification\Infrastructure\Persistence\Seeders\NotificationPermissionsSeeder;
use Modules\Order\Infrastructure\Persistence\Seeders\OrderPermissionsSeeder;
use Modules\Payment\Infrastructure\Persistence\Seeders\PaymentPermissionsSeeder;
use Modules\Promotion\Infrastructure\Persistence\Seeders\PromotionPermissionsSeeder;
use Modules\Review\Infrastructure\Persistence\Seeders\ReviewPermissionsSeeder;
use Modules\Shipment\Infrastructure\Persistence\Seeders\ShipmentPermissionsSeeder;
use Modules\Ticket\Infrastructure\Persistence\Seeders\TicketCategorySeeder;
use Modules\Ticket\Infrastructure\Persistence\Seeders\TicketPermissionsSeeder;

abstract class TestCase extends BaseTestCase
{
    protected function seedIdentityRolesAndPermissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function seedCatalogPermissions(): void
    {
        $this->seed(CatalogPermissionsSeeder::class);
    }

    protected function seedMediaPermissions(): void
    {
        $this->seed(MediaPermissionsSeeder::class);
    }

    protected function seedInventoryPermissions(): void
    {
        $this->seed(InventoryPermissionsSeeder::class);
    }

    protected function seedOrderPermissions(): void
    {
        $this->seed(OrderPermissionsSeeder::class);
    }

    protected function seedPaymentPermissions(): void
    {
        $this->seed(PaymentPermissionsSeeder::class);
    }

    protected function seedShipmentPermissions(): void
    {
        $this->seed(ShipmentPermissionsSeeder::class);
    }

    protected function seedNotificationPermissions(): void
    {
        $this->seed(NotificationPermissionsSeeder::class);
    }

    protected function seedPromotionPermissions(): void
    {
        $this->seed(PromotionPermissionsSeeder::class);
    }

    protected function seedAnalyticsPermissions(): void
    {
        $this->seed(AnalyticsPermissionsSeeder::class);
    }

    protected function seedReviewPermissions(): void
    {
        $this->seed(ReviewPermissionsSeeder::class);
    }

    protected function seedTicketPermissions(): void
    {
        $this->seed(TicketPermissionsSeeder::class);
    }

    protected function seedTicketCategories(): void
    {
        $this->seed(TicketCategorySeeder::class);
    }

    protected function actingAsCustomer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole('customer');

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole('admin');

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * A support agent is a shopper who also handles tickets: the `support` role
     * is added on top of `customer`, never a replacement (mirrors delivery).
     * Requires the `support` role to exist — call seedTicketPermissions() first.
     */
    protected function actingAsSupport(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole('customer');
        $user->assignRole('support');

        Sanctum::actingAs($user);

        return $user;
    }
}
