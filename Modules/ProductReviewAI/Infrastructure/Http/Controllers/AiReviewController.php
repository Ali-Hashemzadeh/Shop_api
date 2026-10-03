<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ManageAiReviewsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\UpdateDraftRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\AiGeneratedReviewResource;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\GenerationDetailResource;

/**
 * Generation detail + draft moderation surface.
 *
 * Note the routes share the `/admin/ai-reviews/{id}` prefix but never collide:
 * GET resolves a generation (and its drafts); PATCH / approve / reject act on a
 * draft. Different HTTP verbs, different resources.
 */
class AiReviewController extends Controller
{
    public function __construct(
        private readonly ProductReviewAIManagerInterface $manager,
    ) {}

    public function show(ManageAiReviewsRequest $request, int $generation): JsonResponse
    {
        return response()->json(new GenerationDetailResource($this->manager->getGeneration($generation)));
    }

    public function update(UpdateDraftRequest $request, int $draft): JsonResponse
    {
        $dto = $this->manager->updateDraft(
            draftId: $draft,
            adminUserId: (int) $request->user()->getAuthIdentifier(),
            changes: $request->changes(),
        );

        return response()->json(new AiGeneratedReviewResource($dto));
    }

    public function approve(ManageAiReviewsRequest $request, int $draft): JsonResponse
    {
        $dto = $this->manager->approveDraft($draft, (int) $request->user()->getAuthIdentifier());

        return response()->json(new AiGeneratedReviewResource($dto));
    }

    public function reject(ManageAiReviewsRequest $request, int $draft): JsonResponse
    {
        $dto = $this->manager->rejectDraft($draft, (int) $request->user()->getAuthIdentifier());

        return response()->json(new AiGeneratedReviewResource($dto));
    }
}
