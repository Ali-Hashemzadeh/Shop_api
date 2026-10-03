<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\JsonExtractor;
use Modules\ProductReviewAI\Application\Support\PromptRenderer;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Domain\DTOs\AnalysisResultDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Infrastructure\Support\AIProviderFactory;

/**
 * AI stage 1 — distil the collected external reviews into structured analysis
 * (positives, negatives, customer profiles, key features). Output must be JSON.
 */
class RunAnalysisAction
{
    public function __construct(
        private readonly AIProviderFactory $providers,
        private readonly PromptResolver $prompts,
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @param  list<ExternalReviewDTO>  $reviews
     * @param  array<string, mixed>  $productContext
     */
    public function handle(int $generationId, array $reviews, array $productContext): AnalysisResultDTO
    {
        $prompt = $this->prompts->active(PromptResolver::ANALYSIS);
        $provider = $this->providers->make();

        $reviewsPayload = array_map(static fn (ExternalReviewDTO $r): array => [
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
        ], $reviews);

        $vars = [
            'product_title' => (string) ($productContext['title'] ?? ''),
            'product_description' => (string) ($productContext['description'] ?? ''),
            'reviews_json' => json_encode($reviewsPayload, JSON_UNESCAPED_UNICODE),
            'review_count' => count($reviews),
        ];

        $system = PromptRenderer::render($prompt->system_prompt, $vars);
        $user = PromptRenderer::render($prompt->user_prompt, $vars);

        $this->log->success($generationId, GenerationLogType::AiAnalysisRequest, [
            'model' => $provider->model(),
            'prompt' => ['name' => $prompt->name, 'version' => $prompt->version],
            'review_count' => count($reviews),
        ]);

        try {
            $raw = $provider->complete($system, $user);
        } catch (\Throwable $e) {
            $this->log->failure($generationId, GenerationLogType::AiAnalysisResponse, $e->getMessage());

            throw $e;
        }

        try {
            $decoded = JsonExtractor::decode($raw);
        } catch (\Throwable $e) {
            $this->log->failure($generationId, GenerationLogType::AiAnalysisResponse, $e->getMessage(), null, [
                'raw' => mb_substr($raw, 0, 2000),
            ]);

            throw $e;
        }

        $analysis = AnalysisResultDTO::fromArray($decoded);

        $this->log->success($generationId, GenerationLogType::AiAnalysisResponse, null, [
            'analysis' => $analysis->toArray(),
            'raw' => mb_substr($raw, 0, 4000),
        ]);

        return $analysis;
    }
}
