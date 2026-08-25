<?php

declare(strict_types=1);

namespace Modules\Review\Domain\DTOs;

/**
 * Result of the create-or-upgrade write path.
 *
 * One review per (user, subject) means POST /reviews sometimes *updates* an
 * existing row in place (the commenter who later became a verified purchaser).
 * The controller needs to know which happened so it can answer 201 vs 200.
 */
class ReviewWriteResultDTO
{
    public function __construct(
        public readonly ReviewDTO $review,
        public readonly bool $created,
    ) {}
}
