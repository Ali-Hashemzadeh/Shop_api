<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Review\Application\Actions\CreateOrUpdateReviewAction;
use Modules\Review\Application\Actions\ModerateReviewAction;
use Modules\Review\Application\Actions\ReplyToReviewAction;
use Modules\Review\Domain\Contracts\ReviewManagerInterface;
use Modules\Review\Domain\DTOs\ReviewDTO;
use Modules\Review\Domain\DTOs\ReviewWriteResultDTO;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Enums\ReviewSubjectType;
use Modules\Review\Domain\Models\Review;

class EloquentReviewManager implements ReviewManagerInterface
{
    public function __construct(
        private readonly MediaManagerInterface $media,
    ) {}

    public function create(
        int $userId,
        string $subjectType,
        int $subjectId,
        ?int $rating,
        string $body,
        array $galleryMediaIds = [],
    ): ReviewWriteResultDTO {
        /** @var Review $review */
        $review = app(CreateOrUpdateReviewAction::class)->handle(
            $userId,
            ReviewSubjectType::from($subjectType),
            $subjectId,
            $rating,
            $body,
            $galleryMediaIds,
        );

        return new ReviewWriteResultDTO(
            review: $this->hydrate($review),
            created: $review->wasRecentlyCreated,
        );
    }

    public function update(
        string $uuid,
        int $userId,
        ?int $rating,
        string $body,
        array $galleryMediaIds = [],
    ): ReviewDTO {
        /** @var Review $existing */
        $existing = Review::query()->where('uuid', $uuid)->firstOrFail();

        // Re-resolve everything against the existing row's subject — the same
        // upsert path as create, so the edit upgrades the review in place
        // (purchase status, rating eligibility) and re-queues moderation.
        /** @var Review $review */
        $review = app(CreateOrUpdateReviewAction::class)->handle(
            (int) $existing->user_id,
            ReviewSubjectType::from((string) $existing->subject_type->value),
            (int) $existing->subject_id,
            $rating,
            $body,
            $galleryMediaIds,
        );

        return $this->hydrate($review);
    }

    public function moderate(string $uuid, string $status): ReviewDTO
    {
        return $this->hydrate(app(ModerateReviewAction::class)->handle($uuid, $status));
    }

    public function reply(string $uuid, string $reply): ReviewDTO
    {
        return $this->hydrate(app(ReplyToReviewAction::class)->handle($uuid, $reply));
    }

    public function getForSubject(string $subjectType, int $subjectId, ?string $sort = null, int $perPage = 15): LengthAwarePaginator
    {
        return Review::query()
            ->where('subject_type', ReviewSubjectType::from($subjectType))
            ->where('subject_id', $subjectId)
            ->where('status', ReviewStatus::Approved->value)
            ->when($sort === 'highest' || $sort === 'lowest', fn ($q) => $this->applyRatingSort($q, $sort))
            ->when($sort === null || $sort === 'newest', fn ($q) => $q->latest('id'))
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Review $review) => $this->hydrate($review));
    }

    public function getForSubjectAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Review::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', ReviewStatus::from($filters['status'])))
            ->when(! empty($filters['subject_type']), fn ($q) => $q->where('subject_type', ReviewSubjectType::from($filters['subject_type'])))
            ->latest('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (Review $review) => $this->hydrate($review));
    }

    public function getSummaryForSubjects(string $subjectType, array $subjectIds): array
    {
        $subjectIds = array_values(array_unique(array_filter(array_map('intval', $subjectIds))));

        if ($subjectIds === []) {
            return [];
        }

        return Review::query()
            ->selectRaw('subject_id, SUM(rating) as rating_sum, COUNT(*) as rating_count')
            ->where('subject_type', ReviewSubjectType::from($subjectType))
            ->whereIn('subject_id', $subjectIds)
            ->where('status', ReviewStatus::Approved->value)
            ->whereNotNull('rating')
            ->groupBy('subject_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->subject_id => [
                    'rating_sum' => (int) $row->rating_sum,
                    'rating_count' => (int) $row->rating_count,
                ],
            ])
            ->all();
    }

    /**
     * Highest/lowest ordering must never let unrated rows (NULL) land first:
     * SQLite sorts NULL low on ASC and first on DESC-adjacent cases, so an
     * explicit IS-NULL guard keeps rated reviews on top both ways.
     */
    private function applyRatingSort($query, string $direction): void
    {
        $dir = $direction === 'lowest' ? 'asc' : 'desc';

        $query->orderByRaw('CASE WHEN rating IS NULL THEN 1 ELSE 0 END')
            ->orderBy('rating', $dir)
            ->orderByDesc('id');
    }

    /**
     * Batch-resolve gallery media ids into public URLs (one media call per
     * review, never one per id).
     */
    private function hydrate(Review $review): ReviewDTO
    {
        return ReviewDTO::fromModel($review, $this->galleryUrlsFor($review));
    }

    /**
     * @return list<string>
     */
    private function galleryUrlsFor(Review $review): array
    {
        $ids = $review->gallery_media_ids ?? [];

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, MediaDTO> $media */
        $media = $this->media->getMediaCollection($ids);

        $urlById = $media->mapWithKeys(fn ($dto): array => [$dto->id => $dto->url])->all();

        // Preserve the submitted order, silently dropping ids that no longer exist.
        return array_values(array_filter(
            array_map(static fn (int $id): ?string => $urlById[$id] ?? null, array_map('intval', $ids)),
        ));
    }
}
