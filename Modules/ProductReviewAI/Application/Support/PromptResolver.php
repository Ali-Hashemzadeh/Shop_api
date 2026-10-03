<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Application\Support;

use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;

/**
 * Resolves the active version of a named prompt template. Missing an active
 * prompt is a hard failure — the module never invents a prompt.
 */
class PromptResolver
{
    public const ANALYSIS = 'review_analysis';

    public const GENERATION = 'review_generation';

    public function active(string $name): AiPrompt
    {
        /** @var AiPrompt|null $prompt */
        $prompt = AiPrompt::query()
            ->where('name', $name)
            ->where('active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null) {
            throw new AiGenerationException("No active AI prompt configured for [{$name}].");
        }

        return $prompt;
    }
}
