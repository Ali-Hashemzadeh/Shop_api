<?php

declare(strict_types=1);

namespace Modules\Promotion\Domain\Exceptions;

use RuntimeException;

/**
 * A coupon could not be applied. Carries the field-keyed message the HTTP layer
 * turns into the repository's standard 422 shape.
 *
 * Invalid, inactive, and unknown codes deliberately share one generic message so
 * the endpoint cannot be used to enumerate which codes exist.
 */
class CouponRejectedException extends RuntimeException
{
    public const GENERIC_MESSAGE = 'This coupon code is invalid.';

    public function __construct(
        string $message = self::GENERIC_MESSAGE,
        public readonly string $field = 'code',
    ) {
        parent::__construct($message);
    }

    public static function generic(): self
    {
        return new self;
    }
}
