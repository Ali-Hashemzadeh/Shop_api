<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * How an author profile contributed to an article. Co-authorship is ordered.
 */
enum BlogAuthorRole: string
{
    case Author = 'author';
    case Editor = 'editor';
    case Contributor = 'contributor';
    case Reviewer = 'reviewer';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
