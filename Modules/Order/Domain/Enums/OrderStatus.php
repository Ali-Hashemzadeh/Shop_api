<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
}
