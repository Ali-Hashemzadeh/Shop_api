<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Exceptions;

use RuntimeException;

final class CartQuantityLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $sku,
        public readonly int $limit,
        public readonly int $requestedQuantity,
    ) {
        parent::__construct("The maximum allowed quantity for this item per order is {$limit}.");
    }

    public function validationMessage(): string
    {
        return "You can add a maximum of {$this->limit} units of this item to one order.";
    }
}
