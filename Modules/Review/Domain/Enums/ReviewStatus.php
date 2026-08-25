<?php

declare(strict_types=1);

namespace Modules\Review\Domain\Enums;

/**
 * Moderation lifecycle of a review.
 *
 * `pending` is system-only: rows enter it on create and on every edit (edited
 * content always needs re-moderation). The admin moderation endpoint may move
 * a review into `approved` or `rejected` — never back into `pending`.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * The statuses an admin may set. Returning a review to `pending` is not a
     * moderation decision, so it is deliberately absent here.
     *
     * @return list<string>
     */
    public static function moderationTargets(): array
    {
        return [self::Approved->value, self::Rejected->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
