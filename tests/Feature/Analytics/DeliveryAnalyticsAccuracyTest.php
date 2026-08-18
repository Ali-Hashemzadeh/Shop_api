<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Analytics\Domain\Contracts\AnalyticsManagerInterface;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Tests\TestCase;

class DeliveryAnalyticsAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_delivery_averages_are_weighted_and_not_averaged_averages(): void
    {
        // Day 1: 5 deliveries of 100 minutes each = 500 total minutes
        for ($i = 1; $i <= 5; $i++) {
            Event::dispatch(new ShipmentDeliveredEvent(
                orderId: 100 + $i,
                userId: 1,
                method: 'local_delivery',
                driverId: 10,
                deliveryMinutes: 100,
                deliveredAt: '2026-08-01 10:00:00',
            ));
        }

        // Day 2: 95 deliveries of 20 minutes each = 1900 total minutes
        for ($i = 1; $i <= 95; $i++) {
            Event::dispatch(new ShipmentDeliveredEvent(
                orderId: 200 + $i,
                userId: 1,
                method: 'local_delivery',
                driverId: 10,
                deliveryMinutes: 20,
                deliveredAt: '2026-08-02 10:00:00',
            ));
        }

        // Total: 100 deliveries, 2400 total minutes -> EXACT weighted average = 24 minutes.
        // (Unweighted average of daily averages would have been (100 + 20) / 2 = 60, which is incorrect).
        $analyticsManager = app(AnalyticsManagerInterface::class);
        $stats = $analyticsManager->getDelivery([
            'from' => '2026-08-01',
            'to' => '2026-08-02',
        ]);

        $this->assertSame(100, $stats->summary['total_delivered']);
        $this->assertSame(24, $stats->summary['overall_average_delivery_minutes']);

        $methodStat = $stats->deliveryByMethod[0];
        $this->assertSame('local_delivery', $methodStat['method']);
        $this->assertSame(100, $methodStat['delivered_count']);
        $this->assertSame(24, $methodStat['average_delivery_minutes']);

        $driverStat = $stats->driverStats[0];
        $this->assertSame(10, $driverStat['driver_id']);
        $this->assertSame(100, $driverStat['completed_count']);
        $this->assertSame(24, $driverStat['average_delivery_minutes']);
    }

    public function test_adding_new_delivery_updates_totals_and_recomputes_average_accurately(): void
    {
        // Delivery 1: 30 minutes
        Event::dispatch(new ShipmentDeliveredEvent(
            orderId: 1,
            userId: 1,
            method: 'local_delivery',
            driverId: 5,
            deliveryMinutes: 30,
            deliveredAt: '2026-08-18 10:00:00',
        ));

        // Delivery 2: 60 minutes
        Event::dispatch(new ShipmentDeliveredEvent(
            orderId: 2,
            userId: 1,
            method: 'local_delivery',
            driverId: 5,
            deliveryMinutes: 60,
            deliveredAt: '2026-08-18 11:00:00',
        ));

        // Total = 90 mins / 2 = 45 mins
        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'delivered_count' => 2,
            'total_delivery_minutes' => 90,
        ]);

        $this->assertDatabaseHas('analytics_driver_stats', [
            'date' => '2026-08-18',
            'driver_id' => 5,
            'completed_count' => 2,
            'total_delivery_minutes' => 90,
        ]);

        $analyticsManager = app(AnalyticsManagerInterface::class);
        $stats = $analyticsManager->getDelivery(['from' => '2026-08-18', 'to' => '2026-08-18']);

        $this->assertSame(45, $stats->deliveryByMethod[0]['average_delivery_minutes']);
        $this->assertSame(45, $stats->driverStats[0]['average_delivery_minutes']);
        $this->assertSame(45, $stats->summary['overall_average_delivery_minutes']);
    }
}
