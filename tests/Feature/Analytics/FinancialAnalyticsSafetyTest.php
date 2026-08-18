<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Order\Domain\DTOs\OrderPaidItemDTO;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Payment\Domain\Events\PaymentCancelledEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;
use Tests\TestCase;

class FinancialAnalyticsSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_payment_successful_event_alone_does_not_mutate_sales_revenue(): void
    {
        Event::dispatch(new PaymentSuccessfulEvent(
            orderId: 500,
            userId: 10,
            gateway: 'zarinpal',
            amount: 250000,
            paymentId: 1,
            paidAt: '2026-08-18 10:00:00',
        ));

        // Payment stats must record the payment
        $this->assertDatabaseHas('analytics_payment_stats', [
            'date' => '2026-08-18',
            'gateway' => 'zarinpal',
            'successful_count' => 1,
            'total_amount' => 250000,
        ]);

        // Sales tables must remain completely empty (zero double counting)
        $this->assertDatabaseCount('analytics_daily_sales', 0);
        $this->assertDatabaseCount('analytics_product_sales', 0);
        $this->assertDatabaseCount('analytics_variant_sales', 0);
        $this->assertDatabaseCount('analytics_category_sales', 0);
        $this->assertDatabaseCount('analytics_customer_stats', 0);
    }

    public function test_payment_failed_and_cancelled_events_do_not_mutate_sales_tables(): void
    {
        Event::dispatch(new PaymentFailedEvent(
            orderId: 501,
            userId: 10,
            gateway: 'zarinpal',
            amount: 100000,
        ));

        Event::dispatch(new PaymentCancelledEvent(
            orderId: 502,
            userId: 10,
            gateway: 'zarinpal',
            amount: 100000,
            cancelledAt: '2026-08-18 11:00:00',
        ));

        $this->assertDatabaseCount('analytics_daily_sales', 0);
        $this->assertDatabaseCount('analytics_product_sales', 0);
        $this->assertDatabaseCount('analytics_category_sales', 0);
        $this->assertDatabaseCount('analytics_customer_stats', 0);

        $this->assertDatabaseHas('analytics_payment_stats', [
            'gateway' => 'zarinpal',
            'failed_count' => 1,
            'cancelled_count' => 1,
        ]);
    }

    public function test_order_paid_event_is_the_single_source_of_revenue_growth(): void
    {
        // 1. Payment succeeds
        Event::dispatch(new PaymentSuccessfulEvent(
            orderId: 505,
            userId: 15,
            gateway: 'zarinpal',
            amount: 100000,
            paymentId: 2,
            paidAt: '2026-08-18 12:00:00',
        ));

        // 2. Order transitions to paid
        Event::dispatch(new OrderPaidEvent(
            orderId: 505,
            userId: 15,
            totalAmount: 100000,
            orderPublicCode: 'bdo-505',
            items: [
                new OrderPaidItemDTO(
                    productId: 1,
                    variantId: 2,
                    quantity: 1,
                    unitPrice: 100000,
                    categoryIds: [10],
                ),
            ],
            paidAt: '2026-08-18 12:00:00',
        ));

        // Total net revenue in analytics_daily_sales must be exactly 100,000 (not 200,000)
        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 1,
            'paid_orders_count' => 1,
            'net_revenue' => 100000,
            'gross_revenue' => 100000,
        ]);

        $this->assertDatabaseHas('analytics_customer_stats', [
            'customer_id' => 15,
            'orders_count' => 1,
            'total_spent' => 100000,
        ]);
    }
}
