<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

use Carbon\Carbon;

/**
 * A reader's follow of a Blog category, tag, or author, with the subject's
 * display name/slug resolved inside Blog.
 */
final class BlogSubscriptionDTO
{
    public function __construct(
        public readonly int $id,
        public readonly string $subjectType,
        public readonly int $subjectId,
        public readonly ?string $subjectName,
        public readonly ?string $subjectSlug,
        public readonly Carbon $createdAt,
    ) {}
}
