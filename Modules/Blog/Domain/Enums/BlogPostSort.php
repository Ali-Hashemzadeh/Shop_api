<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Public listing sorts. Metric sorts read Blog's own maintained blog_post_stats counters.
 */
enum BlogPostSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case MostViewed = 'most_viewed';
    case MostCommented = 'most_commented';
    case Popular = 'popular';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
