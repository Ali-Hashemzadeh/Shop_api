<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Exceptions;

use RuntimeException;

class ShipmentNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Shipment not found.')
    {
        parent::__construct($message);
    }
}
