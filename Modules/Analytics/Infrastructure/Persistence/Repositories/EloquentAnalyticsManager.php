<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Analytics\Domain\Contracts\AnalyticsManagerInterface;
use Modules\Analytics\Domain\DTOs\AnalyticsCustomerStatsDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDailySalesDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDashboardDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsDeliveryStatsDTO;
use Modules\Analytics\Domain\DTOs\AnalyticsProductSalesDTO;
use Modules\Analytics\Domain\Models\AnalyticsCategorySale;
use Modules\Analytics\Domain\Models\AnalyticsCustomerStat;
use Modules\Analytics\Domain\Models\AnalyticsDailySale;
use Modules\Analytics\Domain\Models\AnalyticsDeliveryStat;
use Modules\Analytics\Domain\Models\AnalyticsDriverStat;
use Modules\Analytics\Domain\Models\AnalyticsProductCategory;
use Modules\Analytics\Domain\Models\AnalyticsProductSale;
use Modules\Analytics\Domain\Models\AnalyticsVariantSale;

class EloquentAnalyticsManager implements AnalyticsManagerInterface
{
    public function getDashboard(): AnalyticsDashboardDTO
    {
        // 1. Revenue & order totals
        $dailyTotals = AnalyticsDailySale::query()
            ->selectRaw('
                COALESCE(SUM(gross_revenue), 0) as gross_revenue,
                COALESCE(SUM(net_revenue), 0) as net_revenue,
                COALESCE(SUM(discount_amount), 0) as discount_amount,
                COALESCE(SUM(coupon_amount), 0) as coupon_amount,
                COALESCE(SUM(refund_amount), 0) as refund_amount,
                COALESCE(SUM(orders_count), 0) as total_orders,
                COALESCE(SUM(paid_orders_count), 0) as paid_orders,
                COALESCE(SUM(cancelled_orders_count), 0) as cancelled_orders
            ')
            ->first();

        $revenueSummary = [
            'gross_revenue' => (int) ($dailyTotals->gross_revenue ?? 0),
            'net_revenue' => (int) ($dailyTotals->net_revenue ?? 0),
            'discount_amount' => (int) ($dailyTotals->discount_amount ?? 0),
            'coupon_amount' => (int) ($dailyTotals->coupon_amount ?? 0),
            'refund_amount' => (int) ($dailyTotals->refund_amount ?? 0),
        ];

        $orderSummary = [
            'total_orders' => (int) ($dailyTotals->total_orders ?? 0),
            'paid_orders' => (int) ($dailyTotals->paid_orders ?? 0),
            'cancelled_orders' => (int) ($dailyTotals->cancelled_orders ?? 0),
        ];

        // 2. Best products
        $bestProducts = AnalyticsProductSale::query()
            ->select('product_id')
            ->selectRaw('
                SUM(quantity_sold) as quantity_sold,
                SUM(orders_count) as orders_count,
                SUM(gross_revenue) as gross_revenue,
                SUM(net_revenue) as net_revenue
            ')
            ->groupBy('product_id')
            ->orderByDesc('quantity_sold')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'quantity_sold' => (int) $row->quantity_sold,
                'orders_count' => (int) $row->orders_count,
                'gross_revenue' => (int) $row->gross_revenue,
                'net_revenue' => (int) $row->net_revenue,
            ])
            ->all();

        // 3. Best categories
        $bestCategories = AnalyticsCategorySale::query()
            ->select('category_id')
            ->selectRaw('
                SUM(quantity_sold) as quantity_sold,
                SUM(orders_count) as orders_count,
                SUM(gross_revenue) as gross_revenue,
                SUM(net_revenue) as net_revenue
            ')
            ->groupBy('category_id')
            ->orderByDesc('quantity_sold')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'quantity_sold' => (int) $row->quantity_sold,
                'orders_count' => (int) $row->orders_count,
                'gross_revenue' => (int) $row->gross_revenue,
                'net_revenue' => (int) $row->net_revenue,
            ])
            ->all();

        // 4. Customer summary
        $customerTotals = AnalyticsCustomerStat::query()
            ->selectRaw('
                COUNT(*) as total_customers,
                COALESCE(SUM(total_spent), 0) as total_spent,
                COALESCE(SUM(orders_count), 0) as total_orders
            ')
            ->first();

        $totalCustomers = (int) ($customerTotals->total_customers ?? 0);
        $totalCustomerSpent = (int) ($customerTotals->total_spent ?? 0);
        $totalCustomerOrders = (int) ($customerTotals->total_orders ?? 0);

        $customerSummary = [
            'total_customers' => $totalCustomers,
            'total_spent' => $totalCustomerSpent,
            'average_customer_spend' => $totalCustomers > 0 ? intdiv($totalCustomerSpent, $totalCustomers) : 0,
            'average_order_value' => $totalCustomerOrders > 0 ? intdiv($totalCustomerSpent, $totalCustomerOrders) : 0,
        ];

        return new AnalyticsDashboardDTO(
            revenueSummary: $revenueSummary,
            orderSummary: $orderSummary,
            bestProducts: $bestProducts,
            bestCategories: $bestCategories,
            customerSummary: $customerSummary,
        );
    }

    public function getSales(array $filters = []): AnalyticsDailySalesDTO
    {
        $query = AnalyticsDailySale::query();

        if (! empty($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }

        $records = $query->orderBy('date', 'asc')->get();

        $rows = [];
        $totalGross = 0;
        $totalNet = 0;
        $totalDiscount = 0;
        $totalCoupon = 0;
        $totalRefund = 0;
        $totalOrders = 0;
        $totalPaidOrders = 0;
        $totalCancelledOrders = 0;

        foreach ($records as $record) {
            $rows[] = [
                'date' => (string) $record->date->format('Y-m-d'),
                'orders_count' => (int) $record->orders_count,
                'paid_orders_count' => (int) $record->paid_orders_count,
                'cancelled_orders_count' => (int) $record->cancelled_orders_count,
                'gross_revenue' => (int) $record->gross_revenue,
                'discount_amount' => (int) $record->discount_amount,
                'coupon_amount' => (int) $record->coupon_amount,
                'refund_amount' => (int) $record->refund_amount,
                'net_revenue' => (int) $record->net_revenue,
            ];

            $totalGross += (int) $record->gross_revenue;
            $totalNet += (int) $record->net_revenue;
            $totalDiscount += (int) $record->discount_amount;
            $totalCoupon += (int) $record->coupon_amount;
            $totalRefund += (int) $record->refund_amount;
            $totalOrders += (int) $record->orders_count;
            $totalPaidOrders += (int) $record->paid_orders_count;
            $totalCancelledOrders += (int) $record->cancelled_orders_count;
        }

        $summary = [
            'total_gross_revenue' => $totalGross,
            'total_net_revenue' => $totalNet,
            'total_discount_amount' => $totalDiscount,
            'total_coupon_amount' => $totalCoupon,
            'total_refund_amount' => $totalRefund,
            'total_orders' => $totalOrders,
            'total_paid_orders' => $totalPaidOrders,
            'total_cancelled_orders' => $totalCancelledOrders,
        ];

        return new AnalyticsDailySalesDTO(
            rows: $rows,
            summary: $summary,
        );
    }

    public function getProducts(array $filters = []): AnalyticsProductSalesDTO
    {
        // 1. Products query
        $productQuery = AnalyticsProductSale::query()
            ->select('product_id')
            ->selectRaw('
                SUM(quantity_sold) as quantity_sold,
                SUM(orders_count) as orders_count,
                SUM(gross_revenue) as gross_revenue,
                SUM(discount_amount) as discount_amount,
                SUM(net_revenue) as net_revenue
            ');

        if (! empty($filters['from'])) {
            $productQuery->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $productQuery->where('date', '<=', $filters['to']);
        }

        if (! empty($filters['category_id'])) {
            $categoryId = (int) $filters['category_id'];
            $productIds = AnalyticsProductCategory::where('category_id', $categoryId)->pluck('product_id')->all();
            $productQuery->whereIn('product_id', $productIds ?: [0]);
        }

        $products = $productQuery
            ->groupBy('product_id')
            ->orderByDesc('quantity_sold')
            ->get()
            ->map(fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'quantity_sold' => (int) $row->quantity_sold,
                'orders_count' => (int) $row->orders_count,
                'gross_revenue' => (int) $row->gross_revenue,
                'discount_amount' => (int) $row->discount_amount,
                'net_revenue' => (int) $row->net_revenue,
            ])
            ->all();

        // 2. Variants query
        $variantQuery = AnalyticsVariantSale::query()
            ->select('variant_id')
            ->selectRaw('
                SUM(quantity_sold) as quantity_sold,
                SUM(orders_count) as orders_count,
                SUM(gross_revenue) as gross_revenue,
                SUM(discount_amount) as discount_amount,
                SUM(net_revenue) as net_revenue
            ');

        if (! empty($filters['from'])) {
            $variantQuery->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $variantQuery->where('date', '<=', $filters['to']);
        }

        $variants = $variantQuery
            ->groupBy('variant_id')
            ->orderByDesc('quantity_sold')
            ->get()
            ->map(fn ($row): array => [
                'variant_id' => (int) $row->variant_id,
                'quantity_sold' => (int) $row->quantity_sold,
                'orders_count' => (int) $row->orders_count,
                'gross_revenue' => (int) $row->gross_revenue,
                'discount_amount' => (int) $row->discount_amount,
                'net_revenue' => (int) $row->net_revenue,
            ])
            ->all();

        return new AnalyticsProductSalesDTO(
            products: $products,
            variants: $variants,
        );
    }

    public function getCustomers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $sort = $filters['sort'] ?? 'total_spent';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $allowedSorts = [
            'orders_count',
            'total_spent',
            'total_discount_received',
            'average_order_value',
            'first_order_at',
            'last_order_at',
            'created_at',
        ];

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'total_spent';
        }

        $paginator = AnalyticsCustomerStat::query()
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (AnalyticsCustomerStat $stat): AnalyticsCustomerStatsDTO => AnalyticsCustomerStatsDTO::fromModel($stat));

        return $paginator;
    }

    public function getDelivery(array $filters = []): AnalyticsDeliveryStatsDTO
    {
        // 1. Delivery by method
        $deliveryQuery = AnalyticsDeliveryStat::query()
            ->select('method')
            ->selectRaw('
                SUM(assigned_count) as assigned_count,
                SUM(delivered_count) as delivered_count,
                SUM(failed_count) as failed_count,
                SUM(total_delivery_minutes) as total_delivery_minutes
            ');

        if (! empty($filters['from'])) {
            $deliveryQuery->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $deliveryQuery->where('date', '<=', $filters['to']);
        }

        if (! empty($filters['method'])) {
            $deliveryQuery->where('method', $filters['method']);
        }

        $deliveryRecords = $deliveryQuery->groupBy('method')->get();

        $deliveryByMethod = $deliveryRecords
            ->map(function ($row): array {
                $delivered = (int) $row->delivered_count;
                $totalMinutes = (int) $row->total_delivery_minutes;

                return [
                    'method' => (string) $row->method,
                    'assigned_count' => (int) $row->assigned_count,
                    'delivered_count' => $delivered,
                    'failed_count' => (int) $row->failed_count,
                    'average_delivery_minutes' => $delivered > 0 ? (int) round($totalMinutes / $delivered) : 0,
                ];
            })
            ->all();

        // 2. Driver stats
        $driverQuery = AnalyticsDriverStat::query()
            ->select('driver_id')
            ->selectRaw('
                SUM(assigned_count) as assigned_count,
                SUM(completed_count) as completed_count,
                SUM(failed_count) as failed_count,
                SUM(total_delivery_minutes) as total_delivery_minutes
            ');

        if (! empty($filters['from'])) {
            $driverQuery->where('date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $driverQuery->where('date', '<=', $filters['to']);
        }

        if (! empty($filters['driver_id'])) {
            $driverQuery->where('driver_id', (int) $filters['driver_id']);
        }

        $driverStats = $driverQuery
            ->groupBy('driver_id')
            ->get()
            ->map(function ($row): array {
                $completed = (int) $row->completed_count;
                $totalMinutes = (int) $row->total_delivery_minutes;

                return [
                    'driver_id' => (int) $row->driver_id,
                    'assigned_count' => (int) $row->assigned_count,
                    'completed_count' => $completed,
                    'failed_count' => (int) $row->failed_count,
                    'average_delivery_minutes' => $completed > 0 ? (int) round($totalMinutes / $completed) : 0,
                ];
            })
            ->all();

        // 3. Summary totals
        $totalAssigned = 0;
        $totalDelivered = 0;
        $totalFailed = 0;
        $totalMinutes = 0;

        foreach ($deliveryRecords as $row) {
            $totalAssigned += (int) $row->assigned_count;
            $totalDelivered += (int) $row->delivered_count;
            $totalFailed += (int) $row->failed_count;
            $totalMinutes += (int) $row->total_delivery_minutes;
        }

        $overallAverageMinutes = $totalDelivered > 0 ? (int) round($totalMinutes / $totalDelivered) : 0;

        $summary = [
            'total_assigned' => $totalAssigned,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'overall_average_delivery_minutes' => $overallAverageMinutes,
        ];

        return new AnalyticsDeliveryStatsDTO(
            deliveryByMethod: $deliveryByMethod,
            driverStats: $driverStats,
            summary: $summary,
        );
    }
}
