<?php

declare(strict_types=1);

namespace Modules\Cart\Domain\Exceptions;

use RuntimeException;

class CartItemNotFoundException extends RuntimeException
{
    public function __construct(int $itemId)
    {
        parent::__construct("Cart item {$itemId} not found.");
    }
}
