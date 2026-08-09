<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Enums;

/**
 * Lifecycle of one order's claim on a coupon.
 *
 * RESERVED is created when payment pricing is finalized and survives failed
 * payment attempts — the order is still payable, so the claim must hold.
 * REDEEMED is terminal-success (the order was paid). RELEASED is terminal-failure
 * (cancelled / expired / superseded) and is the only status that does *not* count
 * against usage limits.
 */
enum RedemptionStatus: string
{
    case RESERVED = 'reserved';
    case REDEEMED = 'redeemed';
    case RELEASED = 'released';

    /**
     * The statuses that consume a coupon's usage allowance.
     *
     * @return array<int, string>
     */
    public static function consumingStatuses(): array
    {
        return [self::RESERVED->value, self::REDEEMED->value];
    }
}
