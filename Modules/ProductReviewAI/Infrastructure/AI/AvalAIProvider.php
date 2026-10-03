<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Domain\Contracts\AIProviderInterface;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;

/**
 * AvalAI provider over its OpenAI-compatible chat-completions surface.
 *
 * Pure transport: it runs one completion and returns the raw content. It never
 * falls back to a stub — a missing API key throws IntegrationUnavailableException
 * and any request/response failure throws AiGenerationException, so a broken
 * integration surfaces to the admin instead of producing fake reviews.
 */
class AvalAIProvider implements AIProviderInterface
{
    public function name(): string
    {
        return 'avalai';
    }

    public function model(): string
    {
        return (string) $this->config('model', 'deepseek-v4.1-flash');
    }

    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $apiKey = (string) $this->config('api_key', '');

        if (trim($apiKey) === '') {
            throw new IntegrationUnavailableException(
                'AvalAI is not configured: set AVALAI_API_KEY. AI generation cannot start.'
            );
        }

        $baseUrl = rtrim((string) $this->config('base_url', 'https://api.avalai.ir/v1'), '/');
        $url = $baseUrl.'/chat/completions';

        Log::info('ProductReviewAI AvalAI request', [
            'url' => $url,
            'model' => $this->model(),
            'system_prompt' => mb_substr($systemPrompt, 0, 2000),
            'user_prompt' => mb_substr($userPrompt, 0, 4000),
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout((int) $this->config('timeout', 120))
                ->post($url, [
                    'model' => $this->model(),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => $options['temperature'] ?? (float) $this->config('temperature', 0.85),
                    'response_format' => ['type' => 'json_object'],
                ]);
        } catch (\Throwable $e) {
            Log::warning('ProductReviewAI AvalAI request errored', ['url' => $url, 'error' => $e->getMessage()]);

            throw new AiGenerationException("AvalAI request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            Log::warning('ProductReviewAI AvalAI request failed', [
                'url' => $url,
                'status' => $response->status(),
                'response' => mb_substr($response->body(), 0, 4000),
            ]);

            throw new AiGenerationException(
                "AvalAI returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500)
            );
        }

        Log::info('ProductReviewAI AvalAI response', [
            'url' => $url,
            'status' => $response->status(),
            'response' => mb_substr($response->body(), 0, 4000),
        ]);

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new AiGenerationException('AvalAI returned an empty completion.');
        }

        return $content;
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config("product_review_ai.ai.providers.avalai.{$key}", $default);
    }
}
