<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Enums;

/**
 * Moderation lifecycle of one AI-generated review draft.
 *
 *   pending  → freshly generated, awaiting an admin decision
 *   edited   → an admin changed the draft (name/rating/title/body); still a draft
 *   approved → published into the Review module as a normal review
 *   rejected → discarded (kept for audit; never published)
 *
 * Only `pending` and `edited` drafts may be approved.
 */
enum DraftStatus: string
{
    case Pending = 'pending';
    case Edited = 'edited';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function isPublishable(): bool
    {
        return $this === self::Pending || $this === self::Edited;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
