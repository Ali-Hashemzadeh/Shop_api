<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

use Modules\Analytics\Domain\Models\AnalyticsCustomerStat;

class AnalyticsCustomerStatsDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $customerId,
        public readonly int $ordersCount,
        public readonly int $totalSpent,
        public readonly int $totalDiscountReceived,
        public readonly int $averageOrderValue,
        public readonly ?string $firstOrderAt,
        public readonly ?string $lastOrderAt,
    ) {}

    public static function fromModel(AnalyticsCustomerStat $stat): self
    {
        return new self(
            id: (int) $stat->id,
            customerId: (int) $stat->customer_id,
            ordersCount: (int) $stat->orders_count,
            totalSpent: (int) $stat->total_spent,
            totalDiscountReceived: (int) $stat->total_discount_received,
            averageOrderValue: (int) $stat->average_order_value,
            firstOrderAt: $stat->first_order_at?->toIso8601String(),
            lastOrderAt: $stat->last_order_at?->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'orders_count' => $this->ordersCount,
            'total_spent' => $this->totalSpent,
            'total_discount_received' => $this->totalDiscountReceived,
            'average_order_value' => $this->averageOrderValue,
            'first_order_at' => $this->firstOrderAt,
            'last_order_at' => $this->lastOrderAt,
        ];
    }
}
