<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ManageAiReviewsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\AiPromptResource;

/**
 * Read-only browsing of the versioned prompt templates. Prompts are authored in
 * the DB / seeders; there is no write surface here (editing = a new version,
 * done at the data layer, so history is never rewritten).
 */
class AiPromptController extends Controller
{
    public function __construct(
        private readonly ProductReviewAIManagerInterface $manager,
    ) {}

    public function index(ManageAiReviewsRequest $request): JsonResponse
    {
        $filters = [];

        if ($request->filled('name')) {
            $filters['name'] = (string) $request->string('name');
        }

        if ($request->has('active')) {
            $filters['active'] = $request->boolean('active');
        }

        $paginator = $this->manager->listPrompts($filters, $request->integer('per_page', 15));

        return response()->json(
            AiPromptResource::collection($paginator)->response()->getData(true)
        );
    }

    public function show(ManageAiReviewsRequest $request, int $prompt): JsonResponse
    {
        return response()->json(new AiPromptResource($this->manager->getPrompt($prompt)));
    }
}
