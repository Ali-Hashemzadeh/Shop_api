<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Exceptions;

use RuntimeException;

class StockNotFoundException extends RuntimeException
{
    public function __construct(string $sku)
    {
        parent::__construct("No inventory record found for SKU: {$sku}");
    }
}
