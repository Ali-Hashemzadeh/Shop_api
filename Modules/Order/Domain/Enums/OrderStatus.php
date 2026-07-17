<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    /**
     * Statuses that count as a realized sale for best-seller tallies:
     * any order that reached payment. Excludes pending / cancelled / failed.
     *
     * @return array<int, string>
     */
    public static function soldStatuses(): array
    {
        return [
            self::PAID->value,
            self::PROCESSING->value,
            self::SHIPPED->value,
        ];
    }
}
