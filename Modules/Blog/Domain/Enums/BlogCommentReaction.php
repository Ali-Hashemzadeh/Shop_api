<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Reader reactions on a comment: one per reader per comment.
 */
enum BlogCommentReaction: string
{
    case Like = 'like';
    case Helpful = 'helpful';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
