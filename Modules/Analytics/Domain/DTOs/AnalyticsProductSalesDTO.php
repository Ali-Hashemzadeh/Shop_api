<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

class AnalyticsProductSalesDTO
{
    /**
     * @param  list<array{product_id: int, quantity_sold: int, orders_count: int, gross_revenue: int, discount_amount: int, net_revenue: int}>  $products
     * @param  list<array{variant_id: int, quantity_sold: int, orders_count: int, gross_revenue: int, discount_amount: int, net_revenue: int}>  $variants
     */
    public function __construct(
        public readonly array $products,
        public readonly array $variants,
    ) {}

    public function toArray(): array
    {
        return [
            'products' => $this->products,
            'variants' => $this->variants,
        ];
    }
}
