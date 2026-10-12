<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * The editorial lifecycle of an article.
 *
 *   draft ──submit──▶ in_review ──approve──▶ approved ──publish──▶ published
 *     ▲                  │                     │  ▲                 │
 *     └───return─────────┘                     │  └──unpublish──────┤
 *     ▲                                        └─schedule─▶ scheduled (cron → published)
 *     └───return── archived ◀──archive── (any non-archived status)
 *
 * Only `published` (with published_at <= now and no lapsed unpublish_at) is ever
 * public. The transition map below is the single authority — Actions ask it,
 * never hand-roll status comparisons.
 */
enum BlogPostStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Archived],
            self::InReview => [self::Draft, self::Approved, self::Archived],
            self::Approved => [self::Scheduled, self::Published, self::Archived],
            self::Scheduled => [self::Published, self::Approved, self::Archived],
            self::Published => [self::Approved, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
