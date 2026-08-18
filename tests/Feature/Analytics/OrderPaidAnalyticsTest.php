<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Analytics\Domain\Models\AnalyticsCustomerStat;
use Modules\Order\Domain\DTOs\OrderPaidItemDTO;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Tests\TestCase;

class OrderPaidAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    public function test_order_paid_event_updates_daily_sales(): void
    {
        $event = new OrderPaidEvent(
            orderId: 101,
            userId: 5,
            totalAmount: 180000,
            orderPublicCode: 'bdo-101',
            discountAmount: 20000,
            couponAmount: 10000,
            couponId: 7,
            items: [
                new OrderPaidItemDTO(
                    productId: 10,
                    variantId: 20,
                    quantity: 2,
                    unitPrice: 90000,
                    discountAmount: 20000,
                    categoryIds: [3, 2, 1],
                    discountId: 4,
                    regularUnitPrice: 100000,
                ),
            ],
            paidAt: '2026-08-18 10:00:00',
        );

        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_daily_sales', [
            'date' => '2026-08-18',
            'orders_count' => 1,
            'paid_orders_count' => 1,
            'cancelled_orders_count' => 0,
            'gross_revenue' => 200000,
            'discount_amount' => 20000,
            'coupon_amount' => 10000,
            'net_revenue' => 180000,
        ]);
    }

    public function test_order_paid_event_updates_product_and_variant_sales(): void
    {
        $event = new OrderPaidEvent(
            orderId: 102,
            userId: 5,
            totalAmount: 180000,
            orderPublicCode: 'bdo-102',
            discountAmount: 20000,
            couponAmount: 0,
            items: [
                new OrderPaidItemDTO(
                    productId: 15,
                    variantId: 35,
                    quantity: 3,
                    unitPrice: 60000,
                    discountAmount: 20000,
                    categoryIds: [12],
                    regularUnitPrice: 70000,
                ),
            ],
            paidAt: '2026-08-18 11:00:00',
        );

        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_product_sales', [
            'date' => '2026-08-18',
            'product_id' => 15,
            'quantity_sold' => 3,
            'orders_count' => 1,
            'gross_revenue' => 210000,
            'discount_amount' => 20000,
            'net_revenue' => 180000,
        ]);

        $this->assertDatabaseHas('analytics_variant_sales', [
            'date' => '2026-08-18',
            'variant_id' => 35,
            'quantity_sold' => 3,
            'orders_count' => 1,
            'gross_revenue' => 210000,
            'discount_amount' => 20000,
            'net_revenue' => 180000,
        ]);
    }

    public function test_order_paid_propagates_to_all_ancestor_categories(): void
    {
        // Category hierarchy: Android (30) -> Phones (20) -> Electronics (10)
        $event = new OrderPaidEvent(
            orderId: 103,
            userId: 8,
            totalAmount: 500000,
            orderPublicCode: 'bdo-103',
            items: [
                new OrderPaidItemDTO(
                    productId: 50,
                    variantId: 99,
                    quantity: 2,
                    unitPrice: 250000,
                    discountAmount: 0,
                    categoryIds: [30, 20, 10],
                    regularUnitPrice: 250000,
                ),
            ],
            paidAt: '2026-08-18 12:00:00',
        );

        Event::dispatch($event);

        // Every category in the ancestor chain must be updated!
        foreach ([30, 20, 10] as $categoryId) {
            $this->assertDatabaseHas('analytics_category_sales', [
                'date' => '2026-08-18',
                'category_id' => $categoryId,
                'quantity_sold' => 2,
                'orders_count' => 1,
                'gross_revenue' => 500000,
                'net_revenue' => 500000,
            ]);

            $this->assertDatabaseHas('analytics_product_categories', [
                'product_id' => 50,
                'category_id' => $categoryId,
            ]);
        }
    }

    public function test_customer_stats_calculates_lifetime_metrics(): void
    {
        // First order
        Event::dispatch(new OrderPaidEvent(
            orderId: 201,
            userId: 42,
            totalAmount: 100000,
            discountAmount: 10000,
            couponAmount: 5000,
            paidAt: '2026-08-10 10:00:00',
        ));

        // Second order
        Event::dispatch(new OrderPaidEvent(
            orderId: 202,
            userId: 42,
            totalAmount: 200000,
            discountAmount: 20000,
            couponAmount: 0,
            paidAt: '2026-08-18 15:00:00',
        ));

        $stat = AnalyticsCustomerStat::where('customer_id', 42)->first();
        $this->assertNotNull($stat);
        $this->assertEquals(2, $stat->orders_count);
        $this->assertEquals(300000, $stat->total_spent);
        $this->assertEquals(35000, $stat->total_discount_received);
        $this->assertEquals(150000, $stat->average_order_value);
    }

    public function test_discount_and_coupon_usage_tracking(): void
    {
        $event = new OrderPaidEvent(
            orderId: 301,
            userId: 77,
            totalAmount: 90000,
            discountAmount: 15000,
            couponAmount: 10000,
            couponId: 55,
            items: [
                new OrderPaidItemDTO(
                    productId: 1,
                    variantId: 1,
                    quantity: 1,
                    unitPrice: 100000,
                    discountAmount: 15000,
                    categoryIds: [1],
                    discountId: 88,
                    regularUnitPrice: 115000,
                ),
            ],
            paidAt: '2026-08-18 09:00:00',
        );

        Event::dispatch($event);

        $this->assertDatabaseHas('analytics_discount_usage', [
            'date' => '2026-08-18',
            'discount_id' => 88,
            'usage_count' => 1,
            'discount_amount' => 15000,
            'generated_revenue' => 100000,
        ]);

        $this->assertDatabaseHas('analytics_coupon_usage', [
            'date' => '2026-08-18',
            'coupon_id' => 55,
            'usage_count' => 1,
            'discount_amount' => 10000,
            'generated_revenue' => 90000,
        ]);
    }
}
