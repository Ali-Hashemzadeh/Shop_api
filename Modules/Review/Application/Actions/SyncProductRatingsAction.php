<?php

declare(strict_types=1);

namespace Modules\Review\Application\Actions;

use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

/**
 * Hourly rating-summary sync to Catalog — the `sales_count` pattern.
 *
 * Review aggregates its OWN tables (approved + rated rows only) and pushes an
 * ABSOLUTE per-product tally across the Catalog contract; Catalog never reads
 * reviews and Review never touches products. A product whose reviews are all
 * still pending, rejected, or unrated gets a zeroed tally, so the counters are
 * self-correcting without a global reset: every product that ever had a counter
 * pushed has at least one review row (rows are never deleted), and therefore
 * always appears in the subject sweep below.
 *
 * @return int number of products pushed to Catalog
 */
class SyncProductRatingsAction
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
    ) {}

    public function handle(): int
    {
        // Every product that has ever been reviewed (any status) must be pushed:
        // approved+rated rows feed the sum, everything else pushes zeros.
        $subjectIds = Review::query()
            ->where('subject_type', ReviewSubjectType::Product)
            ->distinct()
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($subjectIds === []) {
            return 0;
        }

        $approved = Review::query()
            ->where('subject_type', ReviewSubjectType::Product)
            ->whereIn('subject_id', $subjectIds)
            ->where('status', ReviewStatus::Approved->value)
            ->whereNotNull('rating')
            ->groupBy('subject_id')
            ->selectRaw('subject_id, SUM(rating) as rating_sum, COUNT(*) as rating_count')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->subject_id => [
                    'rating_sum' => (int) $row->rating_sum,
                    'rating_count' => (int) $row->rating_count,
                ],
            ]);

        foreach ($subjectIds as $productId) {
            $summary = $approved[$productId] ?? ['rating_sum' => 0, 'rating_count' => 0];

            $this->catalog->syncRatingSummary(
                $productId,
                $summary['rating_sum'],
                $summary['rating_count'],
            );
        }

        return count($subjectIds);
    }
}
