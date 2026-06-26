<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Exceptions;

use RuntimeException;

class InvalidAddressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provided address does not exist or does not belong to this user.');
    }
}
