<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Exceptions\ExternalSourceException;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use Modules\ProductReviewAI\Domain\Models\ExternalReview;

/**
 * Collects external reviews for a mapped product and persists them. Both the
 * request and the result are logged; a failure is logged before it propagates.
 */
class CollectExternalReviewsAction
{
    public function __construct(
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @return list<ExternalReviewDTO>
     */
    public function handle(int $generationId, ExternalProductMapping $mapping, ReviewSourceInterface $adapter): array
    {
        $limit = max(1, (int) config('product_review_ai.collection.review_limit', 100));

        try {
            $reviews = $adapter->getReviews($mapping->external_id, $limit);
        } catch (\Throwable $e) {
            $this->log->failure($generationId, GenerationLogType::SourceReviewsFetch, $e->getMessage(), [
                'external_id' => $mapping->external_id,
                'limit' => $limit,
            ]);

            throw $e;
        }

        if ($reviews === []) {
            $message = 'No external reviews were collected for the mapped product.';
            $this->log->failure($generationId, GenerationLogType::SourceReviewsFetch, $message, [
                'external_id' => $mapping->external_id,
                'limit' => $limit,
            ]);

            throw new ExternalSourceException($message);
        }

        foreach ($reviews as $review) {
            ExternalReview::query()->create([
                'mapping_id' => $mapping->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'body' => $review->body,
                'raw_data' => $review->raw === [] ? null : $review->raw,
                'collected_at' => now(),
            ]);
        }

        $this->log->success($generationId, GenerationLogType::SourceReviewsFetch, [
            'external_id' => $mapping->external_id,
            'limit' => $limit,
        ], [
            'collected' => count($reviews),
        ]);

        return $reviews;
    }
}
