<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\GenerationStatsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ListGenerationLogsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ListGenerationsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ManageAiReviewsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\RetryGenerationRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\GenerationLogResource;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\GenerationMonitorResource;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\GenerationStatsResource;

/**
 * Generation monitoring, diagnostics and recovery across all products
 * (/api/v1/admin/ai-reviews/generations*). Thin: validation + authorization in
 * the Form Requests, everything else in the manager / actions.
 *
 * Execution status (pending/processing/completed/failed) is reported here;
 * draft moderation stays on the existing /admin/ai-reviews/{draft} routes.
 */
class AiGenerationMonitorController extends Controller
{
    public function __construct(
        private readonly ProductReviewAIManagerInterface $manager,
    ) {}

    public function index(ListGenerationsRequest $request): JsonResponse
    {
        $paginator = $this->manager->listAllGenerations($request->filters(), $request->perPage());

        return response()->json(
            GenerationMonitorResource::collection($paginator)->response()->getData(true)
        );
    }

    public function stats(GenerationStatsRequest $request): JsonResponse
    {
        return response()->json(new GenerationStatsResource($this->manager->generationStats($request->filters())));
    }

    public function show(ManageAiReviewsRequest $request, int $generation): JsonResponse
    {
        return response()->json(new GenerationMonitorResource($this->manager->getGenerationMonitor($generation)));
    }

    public function logs(ListGenerationLogsRequest $request, int $generation): JsonResponse
    {
        $paginator = $this->manager->listGenerationLogs($generation, $request->wantsDiagnostics(), $request->perPage());

        return response()->json(
            GenerationLogResource::collection($paginator)->response()->getData(true)
        );
    }

    public function retry(RetryGenerationRequest $request, int $generation): JsonResponse
    {
        $dto = $this->manager->retryGeneration($generation, (int) $request->user()->getAuthIdentifier());

        return response()->json(new GenerationMonitorResource($dto), 202);
    }
}
