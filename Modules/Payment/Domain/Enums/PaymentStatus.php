<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Enums;

enum PaymentStatus: string
{
    case INITIATED = 'initiated';
    case CAPTURED = 'captured';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
    case PENDING_CASH = 'pending_cash';
}
