<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Exceptions;

use RuntimeException;

class EmptyCartException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot create an order from an empty cart.');
    }
}
