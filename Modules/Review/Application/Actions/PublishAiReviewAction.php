<?php

declare(strict_types=1);

namespace Modules\Review\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

/**
 * Publishes an approved AI draft as an ordinary, already-moderated review.
 *
 * Unlike CreateOrUpdateReviewAction (the authored-review upsert), this creates a
 * fresh row with no owning user every time — AI reviews are not keyed on a user,
 * so the composite unique index never applies to them (its NULL user_id is
 * distinct). The row is born `approved` because an admin already reviewed and
 * signed off on the draft; provenance is stamped for audit.
 */
class PublishAiReviewAction
{
    public function handle(
        ReviewSubjectType $subjectType,
        int $subjectId,
        string $authorName,
        int $rating,
        ?string $title,
        string $body,
        int $aiGenerationId,
    ): Review {
        return DB::transaction(function () use ($subjectType, $subjectId, $authorName, $rating, $title, $body, $aiGenerationId): Review {
            /** @var Review $review */
            $review = Review::query()->create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'user_id' => null,
                'author_name' => $authorName,
                'rating' => $rating,
                'title' => $title,
                'body' => $body,
                'gallery_media_ids' => null,
                'verified_purchase' => false,
                'status' => ReviewStatus::Approved->value,
                'is_ai_generated' => true,
                'ai_generation_id' => $aiGenerationId,
            ]);

            return $review;
        });
    }
}
