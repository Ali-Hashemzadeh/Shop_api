<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\DTOs;

/**
 * A comment with its visible replies, nested up to the configured max depth.
 */
final class BlogCommentThreadDTO
{
    /**
     * @param  list<BlogCommentThreadDTO>  $replies
     */
    public function __construct(
        public readonly BlogCommentDTO $comment,
        public readonly array $replies,
    ) {}
}
