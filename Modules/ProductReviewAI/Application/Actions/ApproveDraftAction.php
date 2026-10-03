<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\Review\Domain\Contracts\ReviewManagerInterface;
use Modules\Review\Domain\Enums\ReviewSubjectType;

/**
 * Approves a draft and publishes it as an ordinary product review through the
 * Review contract — the ONLY cross-module write this module performs. The
 * created review is already `approved`, has no user account, carries the AI
 * persona name, and records its provenance. The draft keeps the published
 * review id for end-to-end traceability.
 */
class ApproveDraftAction
{
    public function __construct(
        private readonly ReviewManagerInterface $reviews,
        private readonly GenerationLogWriter $log,
    ) {}

    public function handle(int $draftId, int $adminUserId): AiGeneratedReview
    {
        /** @var AiGeneratedReview $draft */
        $draft = AiGeneratedReview::query()->findOrFail($draftId);

        if (! $draft->status->isPublishable()) {
            abort(422, 'Only pending or edited drafts can be approved.');
        }

        return DB::transaction(function () use ($draft, $adminUserId): AiGeneratedReview {
            $review = $this->reviews->createAiReview(
                subjectType: ReviewSubjectType::Product->value,
                subjectId: (int) $draft->product_id,
                authorName: (string) $draft->name,
                rating: (int) $draft->rating,
                title: $draft->title,
                body: (string) $draft->body,
                aiGenerationId: (int) $draft->generation_id,
            );

            $draft->update([
                'status' => DraftStatus::Approved->value,
                'approved_by' => $adminUserId,
                'approved_at' => now(),
                'published_review_id' => $review->id,
            ]);

            $this->log->success($draft->generation_id, GenerationLogType::Approval, [
                'draft_id' => $draft->id,
                'approved_by' => $adminUserId,
            ], [
                'published_review_id' => $review->id,
            ]);

            return $draft->refresh();
        });
    }
}
