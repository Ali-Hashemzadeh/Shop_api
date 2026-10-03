<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Actions;

use Modules\ProductReviewAI\Application\Support\GenerationLogWriter;
use Modules\ProductReviewAI\Application\Support\JsonExtractor;
use Modules\ProductReviewAI\Application\Support\PromptRenderer;
use Modules\ProductReviewAI\Application\Support\PromptResolver;
use Modules\ProductReviewAI\Domain\DTOs\AnalysisResultDTO;
use Modules\ProductReviewAI\Domain\DTOs\GeneratedReviewDraftDTO;
use Modules\ProductReviewAI\Domain\Enums\GenerationLogType;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Infrastructure\Support\AIProviderFactory;

/**
 * AI stage 2 — from product info + analysis, generate natural first-person
 * Persian customer reviews with varied personalities and AI-chosen ratings
 * (never all 5). Output must be JSON: { "reviews": [ {name, rating, title, body} ] }.
 */
class RunGenerationAction
{
    public function __construct(
        private readonly AIProviderFactory $providers,
        private readonly PromptResolver $prompts,
        private readonly GenerationLogWriter $log,
    ) {}

    /**
     * @param  array<string, mixed>  $productContext
     * @return list<GeneratedReviewDraftDTO>
     */
    public function handle(int $generationId, array $productContext, AnalysisResultDTO $analysis, int $count): array
    {
        $prompt = $this->prompts->active(PromptResolver::GENERATION);
        $provider = $this->providers->make();

        $vars = [
            'product_title' => (string) ($productContext['title'] ?? ''),
            'product_description' => (string) ($productContext['description'] ?? ''),
            'analysis_json' => json_encode($analysis->toArray(), JSON_UNESCAPED_UNICODE),
            'count' => $count,
        ];

        $system = PromptRenderer::render($prompt->system_prompt, $vars);
        $user = PromptRenderer::render($prompt->user_prompt, $vars);

        $this->log->success($generationId, GenerationLogType::AiGenerationRequest, [
            'model' => $provider->model(),
            'prompt' => ['name' => $prompt->name, 'version' => $prompt->version],
            'count' => $count,
        ]);

        try {
            $raw = $provider->complete($system, $user);
        } catch (\Throwable $e) {
            $this->log->failure($generationId, GenerationLogType::AiGenerationResponse, $e->getMessage());

            throw $e;
        }

        try {
            $decoded = JsonExtractor::decode($raw);
        } catch (\Throwable $e) {
            $this->log->failure($generationId, GenerationLogType::AiGenerationResponse, $e->getMessage(), null, [
                'raw' => mb_substr($raw, 0, 2000),
            ]);

            throw $e;
        }

        $drafts = $this->parseDrafts($decoded);

        if ($drafts === []) {
            $message = 'AI generation returned no usable reviews.';
            $this->log->failure($generationId, GenerationLogType::AiGenerationResponse, $message, null, [
                'raw' => mb_substr($raw, 0, 2000),
            ]);

            throw new AiGenerationException($message);
        }

        $this->log->success($generationId, GenerationLogType::AiGenerationResponse, null, [
            'generated' => count($drafts),
            'raw' => mb_substr($raw, 0, 4000),
        ]);

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<GeneratedReviewDraftDTO>
     */
    private function parseDrafts(array $decoded): array
    {
        $items = $decoded['reviews'] ?? $decoded;

        if (! is_array($items)) {
            return [];
        }

        $drafts = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            $body = trim((string) ($item['body'] ?? ''));
            $rating = (int) ($item['rating'] ?? 0);

            if ($name === '' || $body === '' || $rating < 1 || $rating > 5) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));

            $drafts[] = new GeneratedReviewDraftDTO(
                name: $name,
                rating: $rating,
                title: $title !== '' ? $title : null,
                body: $body,
            );
        }

        return $drafts;
    }
}
