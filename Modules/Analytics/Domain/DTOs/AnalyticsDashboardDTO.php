<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

class AnalyticsDashboardDTO
{
    /**
     * @param  array{gross_revenue: int, net_revenue: int, discount_amount: int, coupon_amount: int, refund_amount: int}  $revenueSummary
     * @param  array{total_orders: int, paid_orders: int, cancelled_orders: int}  $orderSummary
     * @param  list<array{product_id: int, quantity_sold: int, orders_count: int, gross_revenue: int, net_revenue: int}>  $bestProducts
     * @param  list<array{category_id: int, quantity_sold: int, orders_count: int, gross_revenue: int, net_revenue: int}>  $bestCategories
     * @param  array{total_customers: int, total_spent: int, average_customer_spend: int, average_order_value: int}  $customerSummary
     */
    public function __construct(
        public readonly array $revenueSummary,
        public readonly array $orderSummary,
        public readonly array $bestProducts,
        public readonly array $bestCategories,
        public readonly array $customerSummary,
    ) {}

    public function toArray(): array
    {
        return [
            'revenue_summary' => $this->revenueSummary,
            'order_summary' => $this->orderSummary,
            'best_products' => $this->bestProducts,
            'best_categories' => $this->bestCategories,
            'customer_summary' => $this->customerSummary,
        ];
    }
}
