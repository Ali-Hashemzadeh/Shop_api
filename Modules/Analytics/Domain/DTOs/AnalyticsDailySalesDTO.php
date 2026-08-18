<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

class AnalyticsDailySalesDTO
{
    /**
     * @param  list<array{date: string, orders_count: int, paid_orders_count: int, cancelled_orders_count: int, gross_revenue: int, discount_amount: int, coupon_amount: int, refund_amount: int, net_revenue: int}>  $rows
     * @param  array{total_gross_revenue: int, total_net_revenue: int, total_discount_amount: int, total_coupon_amount: int, total_refund_amount: int, total_orders: int, total_paid_orders: int, total_cancelled_orders: int}  $summary
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $summary,
    ) {}

    public function toArray(): array
    {
        return [
            'daily_sales' => $this->rows,
            'summary' => $this->summary,
        ];
    }
}
