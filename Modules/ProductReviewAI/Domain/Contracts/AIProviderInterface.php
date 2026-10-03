<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Domain\Contracts;

/**
 * A pluggable AI chat-completion provider (AvalAI today).
 *
 * Kept deliberately thin: the provider is pure transport. Prompt construction,
 * JSON extraction, and validation live in the Application actions, so swapping
 * providers never touches business logic. A provider must throw
 * IntegrationUnavailableException when it is not configured (e.g. missing API
 * key) and AiGenerationException on a failed or empty completion — it never
 * fabricates a response.
 */
interface AIProviderInterface
{
    /** Provider code, e.g. `avalai`. */
    public function name(): string;

    /** The concrete model id used for completions, e.g. `deepseek-v4.1-flash`. */
    public function model(): string;

    /**
     * Run one chat completion and return the raw assistant message content.
     * Providers should request a JSON object response where supported.
     *
     * @param  array<string, mixed>  $options
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string;
}
