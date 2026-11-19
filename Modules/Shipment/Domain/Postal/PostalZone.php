<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Postal;

/**
 * Rate-zone codes stored in postal_tariffs_1405.zone_code.
 *
 * Two independent vocabularies — never mix them:
 *   - distance zones come from Identity's province adjacency
 *     (IdentityManagerInterface::getProvinceDistanceType()).
 *   - urban zones (PDF Table 3) come from explicitly configured city lists. They are
 *     NOT the 15% metropolitan delivery surcharge list; a city on that list is not
 *     automatically a `metropolis` rate zone.
 */
enum PostalZone: string
{
    case SameProvince = 'same_province';
    case Neighbor = 'neighbor';
    case NonNeighbor = 'non_neighbor';
    case Metropolis = 'metropolis';
    case ProvinceCenter = 'province_center';
    case OtherCity = 'other_city';

    /** @return list<string> */
    public static function distanceCodes(): array
    {
        return [self::SameProvince->value, self::Neighbor->value, self::NonNeighbor->value];
    }

    /** @return list<string> */
    public static function urbanCodes(): array
    {
        return [self::Metropolis->value, self::ProvinceCenter->value, self::OtherCity->value];
    }
}
