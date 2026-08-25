<?php

declare(strict_types=1);

namespace Modules\Review\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Review\Domain\DTOs\ReviewDTO;
use Modules\Review\Domain\DTOs\ReviewWriteResultDTO;

/**
 * The Review module's public contract. No Eloquent model crosses this wall.
 */
interface ReviewManagerInterface
{
    /**
     * Create a review, or update the caller's existing one in place when they
     * already reviewed the same subject (one review per user per subject).
     *
     * `verified_purchase` is resolved here from the Order contract — never from
     * client input — and every write (create or upgrade) resets the review to
     * `pending` so edited content is always re-moderated. Gallery media ids must
     * already exist in Media; URLs are resolved for the returned DTO.
     *
     * @param  list<int>  $galleryMediaIds
     */
    public function create(
        int $userId,
        string $subjectType,
        int $subjectId,
        ?int $rating,
        string $body,
        array $galleryMediaIds = [],
    ): ReviewWriteResultDTO;

    /**
     * Owner-only edit addressed by the review's public code. Re-resolves
     * `verified_purchase` on every call and resets `status` to `pending`.
     *
     * @param  list<int>  $galleryMediaIds
     */
    public function update(
        string $uuid,
        int $userId,
        ?int $rating,
        string $body,
        array $galleryMediaIds = [],
    ): ReviewDTO;

    /**
     * Admin moderation: move a review to `approved` or `rejected`. Any current
     * status may be re-decided (approved ↔ rejected), but nothing transitions
     * into `pending` — that state is system-only, set on create/edit.
     */
    public function moderate(string $uuid, string $status): ReviewDTO;

    /** Set (or overwrite) the single seller reply. */
    public function reply(string $uuid, string $reply): ReviewDTO;

    /**
     * Public listing for one subject: approved reviews only, regardless of any
     * status a caller may pass. Sort: newest | highest | lowest.
     */
    public function getForSubject(string $subjectType, int $subjectId, ?string $sort = null, int $perPage = 15): LengthAwarePaginator;

    /**
     * Admin listing across every status.
     *
     * Supported keys in $filters: status (pending|approved|rejected),
     * subject_type (see ReviewSubjectType).
     *
     * @param  array<string, mixed>  $filters
     */
    public function getForSubjectAdmin(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Batch rating summaries per subject id, counting ONLY approved rows with a
     * non-null rating (an approved comment without a rating never moves the
     * average). Subjects with no such rows are absent from the result.
     *
     * One page-wide call — no N+1 for consuming modules.
     *
     * @param  list<int>  $subjectIds
     * @return array<int, array{rating_sum: int, rating_count: int}> keyed by subject id
     */
    public function getSummaryForSubjects(string $subjectType, array $subjectIds): array;
}
