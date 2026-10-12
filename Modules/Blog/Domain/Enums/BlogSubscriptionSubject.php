<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * What a reader can follow. Enum-backed whitelist: subjects are Blog-owned and validated inside Blog.
 */
enum BlogSubscriptionSubject: string
{
    case Category = 'category';
    case Tag = 'tag';
    case Author = 'author';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
