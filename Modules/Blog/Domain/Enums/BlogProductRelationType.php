<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * How a Catalog product relates to a shoppable article (loose product_id reference).
 */
enum BlogProductRelationType: string
{
    case Featured = 'featured';
    case Mentioned = 'mentioned';
    case Recommended = 'recommended';
    case Comparison = 'comparison';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
