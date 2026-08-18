<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Analytics\Domain\Models\AnalyticsDailySale;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Tests\TestCase;

class OrderCancelledAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_order_cancelled_event_increments_cancelled_count(): void
    {
        Event::dispatch(new OrderCancelledEvent(
            orderId: 501,
            userId: 12,
            orderPublicCode: 'bdo-501',
            refundAmount: 0,
            wasPaid: false,
            cancelledAt: '2026-08-18 14:00:00',
        ));

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'cancelled_orders_count' => 1,
            'refund_amount' => 0,
        ]);
    }

    public function test_paid_order_cancellation_records_refund_and_adjusts_net_revenue(): void
    {
        // First record a daily sale
        AnalyticsDailySale::create([
            'date' => '2026-08-18',
            'orders_count' => 2,
            'paid_orders_count' => 2,
            'cancelled_orders_count' => 0,
            'gross_revenue' => 300000,
            'discount_amount' => 0,
            'coupon_amount' => 0,
            'refund_amount' => 0,
            'net_revenue' => 300000,
        ]);

        // Cancel a paid order with refund
        Event::dispatch(new OrderCancelledEvent(
            orderId: 502,
            userId: 12,
            orderPublicCode: 'bdo-502',
            refundAmount: 100000,
            wasPaid: true,
            cancelledAt: '2026-08-18 16:00:00',
        ));

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 2,
            'paid_orders_count' => 2,
            'cancelled_orders_count' => 1,
            'refund_amount' => 100000,
            'net_revenue' => 200000,
        ]);
    }
}
