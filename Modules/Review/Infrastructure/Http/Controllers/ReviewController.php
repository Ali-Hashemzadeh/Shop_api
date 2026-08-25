<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Review\Domain\Contracts\ReviewManagerInterface;
use Modules\Review\Infrastructure\Http\Requests\IndexPublicReviewsRequest;
use Modules\Review\Infrastructure\Http\Requests\StoreReviewRequest;
use Modules\Review\Infrastructure\Http\Requests\UpdateReviewRequest;
use Modules\Review\Infrastructure\Http\Resources\ReviewResource;

class ReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ReviewManagerInterface $reviews,
    ) {}

    /**
     * Public listing for one subject: approved reviews only, regardless of any
     * status a caller may pass (the Form Request never forwards one).
     */
    public function index(IndexPublicReviewsRequest $request): JsonResponse
    {
        $paginator = $this->reviews->getForSubject(
            subjectType: (string) $request->string('subject_type'),
            subjectId: $request->integer('subject_id'),
            sort: $request->filled('sort') ? $request->string('sort')->trim()->toString() : null,
            perPage: $request->integer('per_page', 15),
        );

        return response()->json(
            ReviewResource::collection($paginator)->response()->getData(true)
        );
    }

    /**
     * Create, or upgrade-in-place when the caller already reviewed this
     * subject: 201 on the fresh create, 200 on the in-place update.
     */
    public function store(StoreReviewRequest $request): JsonResponse
    {
        $result = $this->reviews->create(
            userId: (int) $request->user()->getAuthIdentifier(),
            subjectType: (string) $request->string('subject_type'),
            subjectId: $request->integer('subject_id'),
            rating: $request->has('rating') ? $request->integer('rating') : null,
            body: (string) $request->string('body'),
            galleryMediaIds: array_map('intval', (array) $request->input('gallery_media_ids', [])),
        );

        return response()->json(
            new ReviewResource($result->review),
            $result->created ? 201 : 200,
        );
    }

    /** Owner-only edit (403 for non-owners via ReviewPolicy). Always 200. */
    public function update(UpdateReviewRequest $request): JsonResponse
    {
        $dto = $this->reviews->update(
            uuid: (string) $request->route('uuid'),
            userId: (int) $request->user()->getAuthIdentifier(),
            rating: $request->has('rating') ? $request->integer('rating') : null,
            body: (string) $request->string('body'),
            galleryMediaIds: array_map('intval', (array) $request->input('gallery_media_ids', [])),
        );

        return response()->json(new ReviewResource($dto));
    }
}
