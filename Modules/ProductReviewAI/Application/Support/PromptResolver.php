<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Modules\ProductReviewAI\Domain\Enums\GenerationFailureCode;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;

/**
 * Resolves the prompt template a run uses. Missing a prompt is a hard failure
 * (AI_CONFIGURATION_ERROR) — the module never invents a prompt.
 *
 * A run pins the prompt versions it was requested with (request_context), so
 * `resolve($name, $version)` returns that exact version even after a newer one
 * is activated; without a pinned version the latest active version is used.
 */
class PromptResolver
{
    public const ANALYSIS = 'review_analysis';

    public const GENERATION = 'review_generation';

    public function active(string $name): AiPrompt
    {
        return $this->resolve($name, null);
    }

    public function resolve(string $name, ?int $version): AiPrompt
    {
        $query = AiPrompt::query()->where('name', $name);

        $prompt = $version !== null
            ? $query->where('version', $version)->first()
            : $query->where('active', true)->orderByDesc('version')->first();

        if (! $prompt instanceof AiPrompt) {
            $label = $version !== null ? "{$name} v{$version}" : $name;

            throw new AiGenerationException(
                "No active AI prompt configured for [{$label}].",
                0,
                null,
                GenerationFailureCode::AiConfigurationError,
            );
        }

        return $prompt;
    }

    /** The latest active version of a prompt, or null when none is configured. */
    public function activeOrNull(string $name): ?AiPrompt
    {
        return AiPrompt::query()
            ->where('name', $name)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();
    }

    public function exists(string $name, int $version): bool
    {
        return AiPrompt::query()->where('name', $name)->where('version', $version)->exists();
    }
}
