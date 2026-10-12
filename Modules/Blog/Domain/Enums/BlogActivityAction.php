<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Enums;

/**
 * Editorial audit-trail actions recorded in blog_post_activity (separate from content revisions).
 */
enum BlogActivityAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case SubmittedForReview = 'submitted_for_review';
    case ReturnedToDraft = 'returned_to_draft';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case RevisionRestored = 'revision_restored';
    case SlugChanged = 'slug_changed';
    case Featured = 'featured';
    case Unfeatured = 'unfeatured';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
