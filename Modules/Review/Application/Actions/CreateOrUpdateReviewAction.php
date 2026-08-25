<?php

declare(strict_types=1);

namespace Modules\Review\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

/**
 * The single write path for customer-authored review content.
 *
 * Purchase status is resolved server-side through the Order contract before
 * anything is written — `verified_purchase` is never client input. The unique
 * (user_id, subject_type, subject_id) index makes the write an upsert, so a
 * commenter who later becomes a verified purchaser upgrades their existing row
 * instead of getting a second one. Every write resets `status` to `pending`:
 * edited content is unmoderated content, even if the previous version was
 * already approved.
 */
class CreateOrUpdateReviewAction
{
    public function __construct(
        private readonly OrderManagerInterface $orders,
    ) {}

    /**
     * @return Review the persisted review; inspect `wasRecentlyCreated` to
     *                distinguish a fresh create from an in-place upgrade
     */
    public function handle(
        int $userId,
        ReviewSubjectType $subjectType,
        int $subjectId,
        ?int $rating,
        string $body,
        array $galleryMediaIds,
    ): Review {
        // Only product subjects can be purchase-verified today; other subject
        // types (future blog support) would resolve their own verification rule.
        $verifiedPurchase = $subjectType === ReviewSubjectType::Product
            && $this->orders->hasPurchasedProduct($userId, $subjectId);

        return DB::transaction(function () use ($userId, $subjectType, $subjectId, $rating, $body, $galleryMediaIds, $verifiedPurchase): Review {
            /** @var Review $review */
            $review = Review::query()->updateOrCreate([
                'user_id' => $userId,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
            ], [
                'rating' => $verifiedPurchase ? $rating : null,
                'body' => $body,
                'gallery_media_ids' => $galleryMediaIds === [] ? null : array_values(array_map('intval', $galleryMediaIds)),
                'verified_purchase' => $verifiedPurchase,
                'status' => ReviewStatus::Pending->value,
            ]);

            return $review;
        });
    }
}
