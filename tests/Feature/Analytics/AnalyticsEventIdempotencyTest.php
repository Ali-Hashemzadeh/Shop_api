<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Analytics\Application\Actions\RecordOrderSaleAction;
use Modules\Order\Domain\DTOs\OrderPaidItemDTO;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Tests\TestCase;

class AnalyticsEventIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_order_paid_event_is_idempotent_and_only_processed_once(): void
    {
        $eventId = (string) Str::uuid();

        $event = new OrderPaidEvent(
            orderId: 100,
            userId: 1,
            totalAmount: 50000,
            orderPublicCode: 'bdo-100',
            discountAmount: 5000,
            couponAmount: 2000,
            items: [
                new OrderPaidItemDTO(
                    productId: 10,
                    variantId: 20,
                    quantity: 2,
                    unitPrice: 25000,
                    discountAmount: 2500,
                    categoryIds: [1],
                    regularUnitPrice: 27500,
                ),
            ],
            paidAt: '2026-08-18 10:00:00',
            eventId: $eventId,
        );

        // Dispatch 1st time
        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_processed_events', [
            'event_id' => $eventId,
            'event_name' => OrderPaidEvent::class,
        ]);

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 1,
            'paid_orders_count' => 1,
            'net_revenue' => 50000,
        ]);

        // Dispatch 2nd time with SAME eventId (simulate queue retry / duplicate delivery)
        Event::dispatch($event);

        // Metrics must NOT be duplicated
        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 1,
            'paid_orders_count' => 1,
            'net_revenue' => 50000,
        ]);

        $this->assertDatabaseCount('analytics_processed_events', 1);
    }

    public function test_order_cancelled_event_is_idempotent(): void
    {
        $eventId = (string) Str::uuid();

        $event = new OrderCancelledEvent(
            orderId: 100,
            userId: 1,
            orderPublicCode: 'bdo-100',
            refundAmount: 50000,
            wasPaid: true,
            cancelledAt: '2026-08-18 12:00:00',
            eventId: $eventId,
        );

        Event::dispatch($event);
        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'cancelled_orders_count' => 1,
            'refund_amount' => 50000,
        ]);

        $this->assertDatabaseCount('analytics_processed_events', 1);
    }

    public function test_payment_successful_event_is_idempotent(): void
    {
        $eventId = (string) Str::uuid();

        $event = new PaymentSuccessfulEvent(
            orderId: 200,
            userId: 2,
            gateway: 'zarinpal',
            amount: 75000,
            paymentId: 1,
            paidAt: '2026-08-18 14:00:00',
            eventId: $eventId,
        );

        Event::dispatch($event);
        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_payment_stats', [
            'date' => '2026-08-18',
            'gateway' => 'zarinpal',
            'successful_count' => 1,
            'total_amount' => 75000,
        ]);

        $this->assertDatabaseCount('analytics_processed_events', 1);
    }

    public function test_shipment_delivered_event_is_idempotent(): void
    {
        $eventId = (string) Str::uuid();

        $event = new ShipmentDeliveredEvent(
            orderId: 300,
            userId: 3,
            orderPublicCode: 'bdo-300',
            shipmentId: 12,
            method: 'local_delivery',
            driverId: 55,
            deliveryMinutes: 45,
            deliveredAt: '2026-08-18 16:00:00',
            eventId: $eventId,
        );

        Event::dispatch($event);
        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_delivery_stats', [
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'delivered_count' => 1,
            'total_delivery_minutes' => 45,
        ]);

        $this->assertDatabaseCount('analytics_processed_events', 1);
    }

    public function test_distinct_events_are_processed_independently(): void
    {
        $event1 = new OrderPaidEvent(
            orderId: 101,
            userId: 1,
            totalAmount: 10000,
            items: [],
            paidAt: '2026-08-18 10:00:00',
        );

        $event2 = new OrderPaidEvent(
            orderId: 102,
            userId: 2,
            totalAmount: 20000,
            items: [],
            paidAt: '2026-08-18 11:00:00',
        );

        Event::dispatch($event1);
        Event::dispatch($event2);

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 2,
            'net_revenue' => 30000,
        ]);

        $this->assertDatabaseCount('analytics_processed_events', 2);
    }

    public function test_failed_transaction_rolls_back_and_allows_retry(): void
    {
        $eventId = (string) Str::uuid();

        $event = new OrderPaidEvent(
            orderId: 103,
            userId: 3,
            totalAmount: 15000,
            items: [],
            paidAt: '2026-08-18 10:00:00',
            eventId: $eventId,
        );

        // Simulate a failure inside transaction
        try {
            DB::transaction(function () use ($event) {
                app(RecordOrderSaleAction::class)->handle($event);
                throw new Exception('Simulated database error during listener execution');
            });
        } catch (Exception $e) {
            // Expected
        }

        // Must not have recorded the event as processed due to rollback
        $this->assertDatabaseMissing('analytics_processed_events', [
            'event_id' => $eventId,
        ]);

        // Now retry the event properly
        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_processed_events', [
            'event_id' => $eventId,
        ]);
        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 1,
            'net_revenue' => 15000,
        ]);
    }
}
