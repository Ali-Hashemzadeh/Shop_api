<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Analytics\Domain\Models\AnalyticsCategorySale;
use Modules\Analytics\Domain\Models\AnalyticsCustomerStat;
use Modules\Analytics\Domain\Models\AnalyticsDailySale;
use Modules\Analytics\Domain\Models\AnalyticsDeliveryStat;
use Modules\Analytics\Domain\Models\AnalyticsDriverStat;
use Modules\Analytics\Domain\Models\AnalyticsProductCategory;
use Modules\Analytics\Domain\Models\AnalyticsProductSale;
use Modules\Analytics\Domain\Models\AnalyticsVariantSale;
use Tests\TestCase;

class AdminAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
        $this->actingAsAdmin();
    }

    public function test_dashboard_endpoint_returns_aggregated_metrics(): void
    {
        AnalyticsDailySale::create([
            'date' => '2026-08-18',
            'orders_count' => 10,
            'paid_orders_count' => 8,
            'cancelled_orders_count' => 2,
            'gross_revenue' => 1000000,
            'discount_amount' => 100000,
            'coupon_amount' => 50000,
            'refund_amount' => 50000,
            'net_revenue' => 800000,
        ]);

        AnalyticsProductSale::create([
            'date' => '2026-08-18',
            'product_id' => 1,
            'quantity_sold' => 5,
            'orders_count' => 4,
            'gross_revenue' => 500000,
            'discount_amount' => 50000,
            'net_revenue' => 450000,
        ]);

        AnalyticsCategorySale::create([
            'date' => '2026-08-18',
            'category_id' => 10,
            'quantity_sold' => 5,
            'orders_count' => 4,
            'gross_revenue' => 500000,
            'discount_amount' => 50000,
            'net_revenue' => 450000,
        ]);

        AnalyticsCustomerStat::create([
            'customer_id' => 1,
            'orders_count' => 2,
            'total_spent' => 200000,
            'total_discount_received' => 20000,
            'average_order_value' => 100000,
            'first_order_at' => now(),
            'last_order_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'revenue_summary' => [
                        'gross_revenue',
                        'net_revenue',
                        'discount_amount',
                        'coupon_amount',
                        'refund_amount',
                    ],
                    'order_summary' => [
                        'total_orders',
                        'paid_orders',
                        'cancelled_orders',
                    ],
                    'best_products',
                    'best_categories',
                    'customer_summary' => [
                        'total_customers',
                        'total_spent',
                        'average_customer_spend',
                        'average_order_value',
                    ],
                ],
            ]);

        $this->assertEquals(1000000, $response->json('data.revenue_summary.gross_revenue'));
        $this->assertEquals(800000, $response->json('data.revenue_summary.net_revenue'));
        $this->assertEquals(10, $response->json('data.order_summary.total_orders'));
        $this->assertCount(1, $response->json('data.best_products'));
        $this->assertCount(1, $response->json('data.best_categories'));
    }

    public function test_sales_endpoint_with_date_filters(): void
    {
        AnalyticsDailySale::create([
            'date' => '2026-08-10',
            'orders_count' => 2,
            'paid_orders_count' => 2,
            'cancelled_orders_count' => 0,
            'gross_revenue' => 200000,
            'net_revenue' => 200000,
        ]);

        AnalyticsDailySale::create([
            'date' => '2026-08-15',
            'orders_count' => 3,
            'paid_orders_count' => 3,
            'cancelled_orders_count' => 0,
            'gross_revenue' => 300000,
            'net_revenue' => 300000,
        ]);

        AnalyticsDailySale::create([
            'date' => '2026-08-20',
            'orders_count' => 5,
            'paid_orders_count' => 5,
            'cancelled_orders_count' => 0,
            'gross_revenue' => 500000,
            'net_revenue' => 500000,
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/sales?from=2026-08-12&to=2026-08-18');

        $response->assertOk()
            ->assertJsonCount(1, 'data.daily_sales')
            ->assertJsonPath('data.summary.total_orders', 3)
            ->assertJsonPath('data.summary.total_gross_revenue', 300000);
    }

    public function test_products_endpoint_with_category_filter(): void
    {
        AnalyticsProductSale::create([
            'date' => '2026-08-18',
            'product_id' => 101,
            'quantity_sold' => 8,
            'orders_count' => 5,
            'gross_revenue' => 400000,
            'net_revenue' => 400000,
        ]);

        AnalyticsProductSale::create([
            'date' => '2026-08-18',
            'product_id' => 202,
            'quantity_sold' => 4,
            'orders_count' => 3,
            'gross_revenue' => 200000,
            'net_revenue' => 200000,
        ]);

        AnalyticsVariantSale::create([
            'date' => '2026-08-18',
            'variant_id' => 303,
            'quantity_sold' => 8,
            'orders_count' => 5,
            'gross_revenue' => 400000,
            'net_revenue' => 400000,
        ]);

        AnalyticsProductCategory::create([
            'product_id' => 101,
            'category_id' => 55,
        ]);

        // Filter by category_id = 55
        $response = $this->getJson('/api/v1/admin/analytics/products?category_id=55');

        $response->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.product_id', 101);
    }

    public function test_customers_endpoint_pagination_and_sorting(): void
    {
        AnalyticsCustomerStat::create([
            'customer_id' => 1,
            'orders_count' => 1,
            'total_spent' => 50000,
            'average_order_value' => 50000,
        ]);

        AnalyticsCustomerStat::create([
            'customer_id' => 2,
            'orders_count' => 5,
            'total_spent' => 500000,
            'average_order_value' => 100000,
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/customers?sort=total_spent&direction=desc&per_page=10');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer_id', 2)
            ->assertJsonPath('data.1.customer_id', 1);
    }

    public function test_delivery_endpoint_returns_method_and_driver_stats(): void
    {
        AnalyticsDeliveryStat::create([
            'date' => '2026-08-18',
            'method' => 'local_delivery',
            'assigned_count' => 4,
            'delivered_count' => 3,
            'failed_count' => 1,
            'average_delivery_minutes' => 25,
        ]);

        AnalyticsDriverStat::create([
            'date' => '2026-08-18',
            'driver_id' => 99,
            'assigned_count' => 4,
            'completed_count' => 3,
            'failed_count' => 1,
            'average_delivery_minutes' => 25,
        ]);

        $response = $this->getJson('/api/v1/admin/analytics/delivery');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'delivery_by_method',
                    'driver_stats',
                    'summary' => [
                        'total_assigned',
                        'total_delivered',
                        'total_failed',
                        'overall_average_delivery_minutes',
                    ],
                ],
            ])
            ->assertJsonPath('data.summary.total_assigned', 4)
            ->assertJsonPath('data.summary.total_delivered', 3)
            ->assertJsonPath('data.summary.total_failed', 1);
    }
}
