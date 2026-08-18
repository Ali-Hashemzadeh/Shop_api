<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

class AnalyticsDeliveryStatsDTO
{
    /**
     * @param  list<array{method: string, assigned_count: int, delivered_count: int, failed_count: int, average_delivery_minutes: int}>  $deliveryByMethod
     * @param  list<array{driver_id: int, assigned_count: int, completed_count: int, failed_count: int, average_delivery_minutes: int}>  $driverStats
     * @param  array{total_assigned: int, total_delivered: int, total_failed: int, overall_average_delivery_minutes: int}  $summary
     */
    public function __construct(
        public readonly array $deliveryByMethod,
        public readonly array $driverStats,
        public readonly array $summary,
    ) {}

    public function toArray(): array
    {
        return [
            'delivery_by_method' => $this->deliveryByMethod,
            'driver_stats' => $this->driverStats,
            'summary' => $this->summary,
        ];
    }
}
