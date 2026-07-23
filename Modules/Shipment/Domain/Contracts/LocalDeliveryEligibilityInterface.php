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

    /**
     * Whether a service area is actually defined.
     *
     * Distinct from isEligible(): with no area configured every address is
     * eligible, which must NOT be read as "the whole world is our delivery
     * zone". Callers that withdraw other methods inside the zone gate on this
     * first, so an unconfigured store keeps offering every method.
     */
    public function hasServiceArea(): bool;
}
