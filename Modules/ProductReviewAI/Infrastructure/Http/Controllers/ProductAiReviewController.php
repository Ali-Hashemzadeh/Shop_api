<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\GenerateReviewsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\ManageAiReviewsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\SaveMappingRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Requests\SearchExternalProductsRequest;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\AiReviewGenerationResource;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\ExternalProductMappingResource;
use Modules\ProductReviewAI\Infrastructure\Http\Resources\ExternalProductResource;

/**
 * Product-scoped admin surface: external product search + mapping, and starting
 * generation runs. All routes are addressed by the product's public code.
 */
class ProductAiReviewController extends Controller
{
    public function __construct(
        private readonly ProductReviewAIManagerInterface $manager,
    ) {}

    public function search(SearchExternalProductsRequest $request, string $uuid): JsonResponse
    {
        $products = $this->manager->searchExternalProducts(
            productUuid: $uuid,
            sourceCode: (string) $request->string('source_code'),
            query: (string) $request->string('q'),
        );

        return response()->json(['data' => ExternalProductResource::collection($products)]);
    }

    public function mappings(ManageAiReviewsRequest $request, string $uuid): JsonResponse
    {
        return response()->json([
            'data' => ExternalProductMappingResource::collection($this->manager->listMappings($uuid)),
        ]);
    }

    public function storeMapping(SaveMappingRequest $request, string $uuid): JsonResponse
    {
        $mapping = $this->manager->saveMapping(
            productUuid: $uuid,
            sourceCode: (string) $request->string('source_code'),
            externalId: (string) $request->string('external_id'),
            externalUrl: $request->filled('external_url') ? (string) $request->string('external_url') : null,
            metadata: (array) $request->input('metadata', []),
        );

        return response()->json(new ExternalProductMappingResource($mapping), 201);
    }

    public function generate(GenerateReviewsRequest $request, string $uuid): JsonResponse
    {
        $result = $this->manager->requestGeneration(
            productUuid: $uuid,
            adminUserId: (int) $request->user()->getAuthIdentifier(),
            count: $request->count(),
            sourceCode: $request->sourceCode(),
            confirmed: $request->confirmed(),
        );

        if ($result->warning) {
            return response()->json([
                'warning' => true,
                'existing_ai_reviews' => $result->existingAiReviews,
            ]);
        }

        return response()->json(new AiReviewGenerationResource($result->generation), 202);
    }

    public function regenerate(GenerateReviewsRequest $request, string $uuid): JsonResponse
    {
        $result = $this->manager->regenerate(
            productUuid: $uuid,
            adminUserId: (int) $request->user()->getAuthIdentifier(),
            count: $request->count(),
            sourceCode: $request->sourceCode(),
        );

        return response()->json(new AiReviewGenerationResource($result->generation), 202);
    }

    public function generations(ManageAiReviewsRequest $request, string $uuid): JsonResponse
    {
        $paginator = $this->manager->listGenerations($uuid, $request->integer('per_page', 15));

        return response()->json(
            AiReviewGenerationResource::collection($paginator)->response()->getData(true)
        );
    }
}
