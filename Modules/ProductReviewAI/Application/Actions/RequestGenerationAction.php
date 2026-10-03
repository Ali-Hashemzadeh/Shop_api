<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Application\Jobs\GenerateProductAIReviewsJob;
use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Application\Support\SourceResolver;
use Modules\ProductReviewAI\Domain\DTOs\AiReviewGenerationDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestResultDTO;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\MappingNotFoundException;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;

/**
 * Creates a generation run and queues its processing. Selection of the external
 * product is already done (a mapping must exist). Existing published AI reviews
 * never block a run — they raise a warning the admin must confirm past.
 *
 * The run row is always created before anything can fail downstream, so the job
 * owns every integration failure and every failure is captured in the log.
 */
class RequestGenerationAction
{
    public function __construct(
        private readonly SourceResolver $sources,
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext  title/description captured
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

        $prompt = AiPrompt::query()
            ->where('name', PromptResolver::GENERATION)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        $generation = DB::transaction(function () use ($productId, $source, $count, $adminUserId, $prompt, $isRegeneration): AiReviewGeneration {
            /** @var AiReviewGeneration $generation */
            $generation = AiReviewGeneration::query()->create([
                'product_id' => $productId,
                'source_id' => $source->id,
                'model' => (string) config('product_review_ai.ai.providers.'.config('product_review_ai.ai.provider').'.model', config('product_review_ai.ai.providers.avalai.model')),
                'count_requested' => $count,
                'status' => GenerationStatus::Pending->value,
                'prompt_name' => $prompt?->name,
                'prompt_version' => $prompt?->version,
                'created_by' => $adminUserId,
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

        // Queue the heavy lifting; scraping + AI never run in the HTTP request.
        GenerateProductAIReviewsJob::dispatch(
            $generation->id,
            $productContext + ['product_id' => $productId],
        );

        return new GenerationRequestResultDTO(
            warning: false,
            existingAiReviews: $existingAiReviews,
            generation: AiReviewGenerationDTO::fromModel($generation->refresh()),
        );
    }
}
