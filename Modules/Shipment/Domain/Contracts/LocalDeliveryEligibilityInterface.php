<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Contracts;

/**
 * Isolated supported-region rule for local delivery. Swap the binding to plug in
 * a real province/city eligibility policy without touching controllers or Actions.
 */
interface LocalDeliveryEligibilityInterface
{
    public function isEligible(?int $provinceId, ?int $cityId): bool;
}
