<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Exceptions;

use Modules\Shipment\Domain\Postal\PostalUnavailableReason;
use RuntimeException;

/**
 * A postal method cannot be quoted for this parcel/route. The 1405 engine is
 * fail-closed: it throws this instead of inventing a price, and the integration
 * seam turns it into `available=false` (method list) or a 422 (checkout). There is
 * no static-price fallback.
 */
class PostalServiceUnavailableException extends RuntimeException
{
    /**
     * @param  list<string>  $skus  affected SKUs (only for WeightMissing)
     */
    public function __construct(
        public readonly PostalUnavailableReason $reason,
        string $detail = '',
        public readonly array $skus = [],
        public readonly ?string $rateZone = null,
    ) {
        parent::__construct($detail !== '' ? $detail : $reason->message());
    }
}
