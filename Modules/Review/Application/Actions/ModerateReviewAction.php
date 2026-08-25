<?php

declare(strict_types=1);

namespace Modules\Review\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Models\Review;

/**
 * Admin moderation. Any current status may be re-decided (`approved ↔ rejected`
 * re-review is legal), but nothing ever transitions into `pending` — that state
 * belongs to the system, set on create and on every edit.
 */
class ModerateReviewAction
{
    public function handle(string $uuid, string $status): Review
    {
        $target = ReviewStatus::from($status);

        if ($target === ReviewStatus::Pending) {
            abort(422, 'A review cannot be moved back to pending.');
        }

        /** @var Review $review */
        $review = Review::query()->where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($review, $target): Review {
            $review->update(['status' => $target]);

            return $review;
        });
    }
}
