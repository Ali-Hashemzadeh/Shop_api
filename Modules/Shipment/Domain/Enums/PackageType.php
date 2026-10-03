<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Enums;

/**
 * Package classification affecting the Post tariff. `standard` is the only type the
 * checkout produces today (there is no per-product fragile flag yet); `fragile` is
 * defined so the tariff table and calculator are ready for one without a schema change.
 */
enum PackageType: string
{
    case Standard = 'standard';
    case Fragile = 'fragile';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Fragile => 'Fragile',
        };
    }
}
