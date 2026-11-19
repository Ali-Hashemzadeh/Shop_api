<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\DTOs\ProductDTO;
use Modules\ProductReviewAI\Application\Actions\ApproveDraftAction;
use Modules\ProductReviewAI\Application\Actions\RejectDraftAction;
use Modules\ProductReviewAI\Application\Actions\RequestGenerationAction;
use Modules\ProductReviewAI\Application\Actions\RetryGenerationAction;
use Modules\ProductReviewAI\Application\Actions\SaveProductMappingAction;
use Modules\ProductReviewAI\Application\Actions\SearchExternalProductsAction;
use Modules\ProductReviewAI\Application\Actions\UpdateDraftAction;
use Modules\ProductReviewAI\Domain\Contracts\ProductReviewAIManagerInterface;
use Modules\ProductReviewAI\Domain\DTOs\AiGeneratedReviewDTO;
use Modules\ProductReviewAI\Domain\DTOs\AiPromptDTO;
use Modules\ProductReviewAI\Domain\DTOs\AiReviewGenerationDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductMappingDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationDetailDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationMonitorDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestResultDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationStatsDTO;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * The workflow facade. It resolves the Catalog product (by public code, via the
 * contract — never a cross-module query) into the internal integer id + AI
 * context, then delegates each step to a single-responsibility Action.
 */
class EloquentProductReviewAIManager implements ProductReviewAIManagerInterface
{
    public function __construct(
        private readonly CatalogManagerInterface $catalog,
        private readonly SearchExternalProductsAction $search,
        private readonly SaveProductMappingAction $saveMapping,
        private readonly RequestGenerationAction $requestGeneration,
        private readonly UpdateDraftAction $updateDraft,
        private readonly ApproveDraftAction $approveDraft,
        private readonly RejectDraftAction $rejectDraft,
        private readonly RetryGenerationAction $retryGeneration,
        private readonly GenerationMonitorRepository $monitor,
    ) {}

    public function searchExternalProducts(string $productUuid, string $sourceCode, string $query): array
    {
        $this->resolveProduct($productUuid);

        return $this->search->handle($sourceCode, $query);
    }

    public function saveMapping(
        string $productUuid,
        string $sourceCode,
        string $externalId,
        ?string $externalUrl,
        array $metadata = [],
    ): ExternalProductMappingDTO {
        $product = $this->resolveProduct($productUuid);

        $mapping = $this->saveMapping->handle($product->id, $sourceCode, $externalId, $externalUrl, $metadata);

        return ExternalProductMappingDTO::fromModel($mapping);
    }

    public function listMappings(string $productUuid): array
    {
        $product = $this->resolveProduct($productUuid);

        return ExternalProductMapping::query()
            ->with('source')
            ->where('product_id', $product->id)
            ->latest('id')
            ->get()
            ->map(fn (ExternalProductMapping $m): ExternalProductMappingDTO => ExternalProductMappingDTO::fromModel($m))
            ->all();
    }

    public function requestGeneration(
        string $productUuid,
        int $adminUserId,
        int $count,
        string $sourceCode,
        bool $confirmed,
    ): GenerationRequestResultDTO {
        $product = $this->resolveProduct($productUuid);

        return $this->requestGeneration->handle(
            productId: $product->id,
            adminUserId: $adminUserId,
            count: $count,
            sourceCode: $sourceCode,
            confirmed: $confirmed,
            isRegeneration: false,
            productContext: $this->contextFor($product),
        );
    }

    public function regenerate(
        string $productUuid,
        int $adminUserId,
        int $count,
        string $sourceCode,
    ): GenerationRequestResultDTO {
        $product = $this->resolveProduct($productUuid);

        return $this->requestGeneration->handle(
            productId: $product->id,
            adminUserId: $adminUserId,
            count: $count,
            sourceCode: $sourceCode,
            confirmed: true,
            isRegeneration: true,
            productContext: $this->contextFor($product),
        );
    }

    public function listGenerations(string $productUuid, int $perPage = 15): LengthAwarePaginator
    {
        $product = $this->resolveProduct($productUuid);

        return AiReviewGeneration::query()
            ->where('product_id', $product->id)
            ->latest('id')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (AiReviewGeneration $g): AiReviewGenerationDTO => AiReviewGenerationDTO::fromModel($g));
    }

    public function listPrompts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return AiPrompt::query()
            ->when(! empty($filters['name']), fn ($q) => $q->where('name', $filters['name']))
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, fn ($q) => $q->where('active', (bool) $filters['active']))
            ->orderBy('name')
            ->orderByDesc('version')
            ->paginate(min(max($perPage, 1), 100))
            ->through(fn (AiPrompt $p): AiPromptDTO => AiPromptDTO::fromModel($p));
    }

    public function getPrompt(int $promptId): AiPromptDTO
    {
        return AiPromptDTO::fromModel(AiPrompt::query()->findOrFail($promptId));
    }

    public function getGeneration(int $generationId): GenerationDetailDTO
    {
        /** @var AiReviewGeneration $generation */
        $generation = AiReviewGeneration::query()->findOrFail($generationId);

        $drafts = AiGeneratedReview::query()
            ->where('generation_id', $generation->id)
            ->latest('id')
            ->get()
            ->map(fn (AiGeneratedReview $r): AiGeneratedReviewDTO => AiGeneratedReviewDTO::fromModel($r))
            ->all();

        return new GenerationDetailDTO(
            generation: AiReviewGenerationDTO::fromModel($generation),
            drafts: $drafts,
        );
    }

    public function listAllGenerations(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $resolved = $this->resolveProductFilter($filters);

        return $this->monitor->paginate($resolved, min(max($perPage, 1), 100));
    }

    public function getGenerationMonitor(int $generationId): GenerationMonitorDTO
    {
        return $this->monitor->find($generationId) ?? abort(404, 'Generation not found.');
    }

    public function listGenerationLogs(int $generationId, bool $includeDiagnostics, int $perPage = 20): LengthAwarePaginator
    {
        if (! $this->monitor->exists($generationId)) {
            abort(404, 'Generation not found.');
        }

        return $this->monitor->logs($generationId, $includeDiagnostics, min(max($perPage, 1), 100));
    }

    public function generationStats(array $filters): GenerationStatsDTO
    {
        return $this->monitor->stats($this->resolveProductFilter($filters));
    }

    public function retryGeneration(int $generationId, int $adminUserId): GenerationMonitorDTO
    {
        $retry = $this->retryGeneration->handle($generationId, $adminUserId);

        return $this->monitor->find((int) $retry->id) ?? abort(404, 'Generation not found.');
    }

    public function updateDraft(int $draftId, int $adminUserId, array $changes): AiGeneratedReviewDTO
    {
        return AiGeneratedReviewDTO::fromModel($this->updateDraft->handle($draftId, $adminUserId, $changes));
    }

    public function approveDraft(int $draftId, int $adminUserId): AiGeneratedReviewDTO
    {
        return AiGeneratedReviewDTO::fromModel($this->approveDraft->handle($draftId, $adminUserId));
    }

    public function rejectDraft(int $draftId, int $adminUserId): AiGeneratedReviewDTO
    {
        return AiGeneratedReviewDTO::fromModel($this->rejectDraft->handle($draftId, $adminUserId));
    }

    private function resolveProduct(string $productUuid): ProductDTO
    {
        // Admin context: a draft product must still be reachable.
        $product = $this->catalog->findProductAdmin($productUuid);

        if ($product === null) {
            abort(404, 'Product not found.');
        }

        return $product;
    }

    /**
     * Replace a `product_uuid` filter (public code) with the internal id, via the
     * Catalog contract. An unknown code is a validation error, not an empty list.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function resolveProductFilter(array $filters): array
    {
        if (isset($filters['product_uuid'])) {
            $product = $this->catalog->findProductAdmin((string) $filters['product_uuid']);

            if ($product === null) {
                throw ValidationException::withMessages([
                    'product_uuid' => 'The selected product does not exist.',
                ]);
            }

            $filters['product_id'] = (int) $product->id;
        }

        unset($filters['product_uuid']);

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(ProductDTO $product): array
    {
        return [
            'product_id' => $product->id,
            'uuid' => $product->uuid,
            'title' => $product->title,
            'description' => $product->description,
        ];
    }
}
