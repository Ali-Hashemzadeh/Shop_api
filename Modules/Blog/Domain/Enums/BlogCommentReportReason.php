<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Why a reader reported a comment.
 */
enum BlogCommentReportReason: string
{
    case Spam = 'spam';
    case Abuse = 'abuse';
    case OffTopic = 'off_topic';
    case Misinformation = 'misinformation';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
