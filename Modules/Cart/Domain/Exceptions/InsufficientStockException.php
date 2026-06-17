<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $sku, int $requested, int $available)
    {
        parent::__construct(
            "Insufficient stock for SKU '{$sku}': requested {$requested}, available {$available}."
        );
    }
}
