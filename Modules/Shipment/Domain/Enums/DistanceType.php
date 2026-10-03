<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

/**
 * The three shipping distance bands. Its string values are the shared primitive
 * vocabulary returned by IdentityManagerInterface::getProvinceDistanceType() and
 * stored in post_tariffs.distance_type — kept in sync deliberately so the two
 * modules agree without importing each other's types.
 */
enum DistanceType: string
{
    case SameProvince = 'same_province';
    case Neighbor = 'neighbor';
    case NonNeighbor = 'non_neighbor';

    public function label(): string
    {
        return match ($this) {
            self::SameProvince => 'Same province',
            self::Neighbor => 'Neighbouring province',
            self::NonNeighbor => 'Non-neighbouring province',
        };
    }
}
