<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Moderation state of a comment report. Only open reports count as active.
 */
enum BlogCommentReportStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Dismissed = 'dismissed';
    case Actioned = 'actioned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
