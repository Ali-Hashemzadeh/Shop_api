<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Review\Domain\Contracts\ReviewManagerInterface;
use Modules\Review\Infrastructure\Http\Requests\AdminIndexReviewsRequest;
use Modules\Review\Infrastructure\Http\Requests\ModerateReviewRequest;
use Modules\Review\Infrastructure\Http\Requests\ReplyToReviewRequest;
use Modules\Review\Infrastructure\Http\Resources\ReviewResource;

/**
 * Admin moderation surface. Authorization (`review.view-admin` /
 * `review.moderate`) is enforced in the Form Requests, so unauthorized users
 * get 403 before validation runs.
 */
class AdminReviewController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ReviewManagerInterface $reviews,
    ) {}

    public function index(AdminIndexReviewsRequest $request): JsonResponse
    {
        $filters = array_filter([
            'status' => $request->filled('status') ? $request->string('status')->trim()->toString() : null,
            'subject_type' => $request->filled('subject_type') ? $request->string('subject_type')->trim()->toString() : null,
        ], fn ($v) => $v !== null);

        $paginator = $this->reviews->getForSubjectAdmin($filters, $request->integer('per_page', 15));

        return response()->json(
            ReviewResource::collection($paginator)->response()->getData(true)
        );
    }

    public function moderate(ModerateReviewRequest $request): JsonResponse
    {
        $dto = $this->reviews->moderate(
            (string) $request->route('uuid'),
            (string) $request->string('status'),
        );

        return response()->json(new ReviewResource($dto));
    }

    public function reply(ReplyToReviewRequest $request): JsonResponse
    {
        $dto = $this->reviews->reply(
            (string) $request->route('uuid'),
            (string) $request->string('reply'),
        );

        return response()->json(new ReviewResource($dto));
    }
}
