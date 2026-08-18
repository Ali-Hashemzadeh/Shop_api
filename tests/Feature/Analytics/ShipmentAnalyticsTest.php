<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveryFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;
use Tests\TestCase;

class ShipmentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_shipment_assigned_event_updates_delivery_and_driver_stats(): void
    {
        Event::dispatch(new ShipmentAssignedToDeliveryEvent(
            shipmentId: 1,
            shipmentPublicCode: 'bds-123456',
            orderId: 10,
            deliveryUserId: 99,
            deliveryDate: '2026-08-18',
            deliveryStartsAt: '10:00',
            deliveryEndsAt: '11:30',
        ));

        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'assigned_count' => 1,
            'delivered_count' => 0,
            'failed_count' => 0,
        ]);

        $this->assertDatabaseHas('analytics_driver_stats', [
            'date' => '2026-08-18',
            'driver_id' => 99,
            'assigned_count' => 1,
            'completed_count' => 0,
            'failed_count' => 0,
        ]);
    }

    public function test_shipment_handed_to_post_updates_postal_delivery_stats(): void
    {
        Event::dispatch(new ShipmentHandedToPostEvent(
            orderId: 20,
            userId: 5,
            orderPublicCode: 'bdo-20',
            trackingCode: 'POST-12345',
        ));

        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => now()->toDateString(),
            'method' => 'post_standard',
            'assigned_count' => 1,
            'delivered_count' => 1,
        ]);
    }

    public function test_shipment_delivered_updates_delivered_count_and_duration(): void
    {
        Event::dispatch(new ShipmentDeliveredEvent(
            orderId: 30,
            userId: 7,
            orderPublicCode: 'bdo-30',
            shipmentId: 5,
            method: 'local_delivery',
            driverId: 99,
            deliveryMinutes: 30,
            deliveredAt: '2026-08-18 11:30:00',
        ));

        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'delivered_count' => 1,
            'total_delivery_minutes' => 30,
        ]);

        $this->assertDatabaseHas('analytics_driver_stats', [
            'date' => '2026-08-18',
            'driver_id' => 99,
            'completed_count' => 1,
            'total_delivery_minutes' => 30,
        ]);
    }

    public function test_shipment_failed_updates_failed_counts(): void
    {
        Event::dispatch(new ShipmentDeliveryFailedEvent(
            orderId: 40,
            userId: 9,
            orderPublicCode: 'bdo-40',
            shipmentId: 6,
            method: 'local_delivery',
            driverId: 99,
            reason: 'Customer not at home',
            failedAt: '2026-08-18 12:00:00',
        ));

        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'failed_count' => 1,
        ]);

        $this->assertDatabaseHas('analytics_driver_stats', [
            'date' => '2026-08-18',
            'driver_id' => 99,
            'failed_count' => 1,
        ]);
    }
}
