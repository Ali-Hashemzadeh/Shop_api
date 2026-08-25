<?php

declare(strict_types=1);

namespace Modules\Review\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Review\Domain\Models\Review;

/**
 * Set (or overwrite) the single seller reply. There is one reply per review,
 * no thread; overwriting is allowed by design.
 */
class ReplyToReviewAction
{
    public function handle(string $uuid, string $reply): Review
    {
        /** @var Review $review */
        $review = Review::query()->where('uuid', $uuid)->firstOrFail();

        return DB::transaction(function () use ($review, $reply): Review {
            $review->update([
                'seller_reply' => $reply,
                'seller_reply_at' => now(),
            ]);

            return $review;
        });
    }
}
