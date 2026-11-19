<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Postal;

/**
 * Iran Post 1405 service families. The scope decides which rate-zone vocabulary a
 * service is priced in:
 *   - non_priority / specialized / intercity → distance zones (same_province / neighbor / non_neighbor)
 *   - urban                                   → urban zones (metropolis / province_center / other_city)
 */
enum PostalScope: string
{
    case NonPriority = 'non_priority';
    case Specialized = 'specialized';
    case Urban = 'urban';
    case Intercity = 'intercity';

    /** @return list<string> the zone codes a tariff row of this scope may use */
    public function zoneCodes(): array
    {
        return $this === self::Urban
            ? PostalZone::urbanCodes()
            : PostalZone::distanceCodes();
    }
}
