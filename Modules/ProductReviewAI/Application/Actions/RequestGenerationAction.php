<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Application\Support\GenerationDispatcher;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\DTOs\AiReviewGenerationDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestContext;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestResultDTO;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStage;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\MappingNotFoundException;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * Creates a generation run and queues its processing. Selection of the external
 * product is already done (a mapping must exist). Existing published AI reviews
 * never block a run — they raise a warning the admin must confirm past.
 *
 * The run row is always created before anything can fail downstream, so the job
 * owns every integration failure and every failure is captured in the log.
 *
 * The row stores an immutable request snapshot (request_context: product
 * context, source, mapping/external id, count, provider + model, pinned prompt
 * versions, collection limit). The pipeline executes from it and a retry copies
 * it. The job is dispatched only after the row commits; a failed push marks the
 * run failed (QUEUE_PROCESSING_ERROR) instead of leaving an orphaned pending row.
 */
class RequestGenerationAction
{
    public function __construct(
        private readonly SourceResolver $sources,
        private readonly GenerationLogWriter $log,
        private readonly PromptResolver $prompts,
        private readonly GenerationDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext  id/uuid/title/description captured
     *                                                at request time for the AI prompt
     */
    public function handle(
        int $productId,
        int $adminUserId,
        int $count,
        string $sourceCode,
        bool $confirmed,
        bool $isRegeneration = false,
        array $productContext = [],
    ): GenerationRequestResultDTO {
        [$source] = $this->sources->resolve($sourceCode);

        $mapping = ExternalProductMapping::query()
            ->where('product_id', $productId)
            ->where('source_id', $source->id)
            ->first();

        if (! $mapping instanceof ExternalProductMapping) {
            throw new MappingNotFoundException(
                'No external product is mapped for this source. Search and select one first.'
            );
        }

        $existingAiReviews = AiGeneratedReview::query()
            ->where('product_id', $productId)
            ->where('status', DraftStatus::Approved->value)
            ->count();

        // A plain generate must warn (not block) when AI reviews already exist;
        // regeneration is explicitly "do it again" and proceeds unconditionally.
        if (! $isRegeneration && ! $confirmed && $existingAiReviews > 0) {
            return new GenerationRequestResultDTO(
                warning: true,
                existingAiReviews: $existingAiReviews,
                generation: null,
            );
        }

        $analysisPrompt = $this->prompts->activeOrNull(PromptResolver::ANALYSIS);
        $generationPrompt = $this->prompts->activeOrNull(PromptResolver::GENERATION);

        $provider = (string) config('product_review_ai.ai.provider');
        $model = (string) config('product_review_ai.ai.providers.'.$provider.'.model', config('product_review_ai.ai.providers.avalai.model'));

        $context = new GenerationRequestContext(
            productId: $productId,
            productUuid: isset($productContext['uuid']) ? (string) $productContext['uuid'] : null,
            productTitle: (string) ($productContext['title'] ?? ''),
            productDescription: isset($productContext['description']) ? (string) $productContext['description'] : null,
            sourceId: (int) $source->id,
            sourceCode: (string) $source->code,
            mappingId: (int) $mapping->id,
            externalId: (string) $mapping->external_id,
            count: $count,
            aiProvider: $provider,
            aiModel: $model,
            analysisPromptName: $analysisPrompt?->name,
            analysisPromptVersion: $analysisPrompt?->version !== null ? (int) $analysisPrompt->version : null,
            generationPromptName: $generationPrompt?->name,
            generationPromptVersion: $generationPrompt?->version !== null ? (int) $generationPrompt->version : null,
            reviewLimit: max(1, (int) config('product_review_ai.collection.review_limit', 100)),
        );

        $generation = DB::transaction(function () use ($productId, $source, $count, $adminUserId, $generationPrompt, $isRegeneration, $model, $context): AiReviewGeneration {
            /** @var AiReviewGeneration $generation */
            $generation = AiReviewGeneration::query()->create([
                'product_id' => $productId,
                'source_id' => $source->id,
                'model' => $model,
                'count_requested' => $count,
                'status' => GenerationStatus::Pending->value,
                'current_stage' => GenerationStage::Queued->value,
                'prompt_name' => $generationPrompt?->name,
                'prompt_version' => $generationPrompt?->version,
                'created_by' => $adminUserId,
                'request_context' => $context->toArray(),
            ]);

            if ($isRegeneration) {
                $this->log->success($generation->id, GenerationLogType::Regeneration, [
                    'product_id' => $productId,
                    'count' => $count,
                    'requested_by' => $adminUserId,
                ]);
            }

            return $generation;
        });

        // Queue the heavy lifting after commit; scraping + AI never run in the HTTP request.
        $this->dispatcher->dispatch($generation->id, $context->productContext());

        return new GenerationRequestResultDTO(
            warning: false,
            existingAiReviews: $existingAiReviews,
            generation: AiReviewGenerationDTO::fromModel($generation->refresh()),
        );
    }
}
