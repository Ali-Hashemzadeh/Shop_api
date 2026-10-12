<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Moderation state of a Blog comment. Only approved comments are ever public.
 */
enum BlogCommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
