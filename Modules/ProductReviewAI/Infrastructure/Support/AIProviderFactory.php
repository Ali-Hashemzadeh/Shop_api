<?php

declare(strict_types=1);

namespace Modules\ProductReviewAI\Infrastructure\Support;

use Modules\ProductReviewAI\Domain\Contracts\AIProviderInterface;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Infrastructure\AI\AvalAIProvider;

/**
 * Resolves the configured AI provider. Business logic depends on
 * AIProviderInterface only, so swapping providers is a config change.
 */
class AIProviderFactory
{
    /** @var array<string, class-string<AIProviderInterface>> */
    private array $providers = [
        'avalai' => AvalAIProvider::class,
    ];

    public function make(?string $provider = null): AIProviderInterface
    {
        $provider = $provider ?: (string) config('product_review_ai.ai.provider');

        if (! isset($this->providers[$provider])) {
            throw new AiGenerationException("Unknown AI provider: [{$provider}].");
        }

        // Container-resolved so tests can bind a test double.
        return app($this->providers[$provider]);
    }

    /**
     * @param  class-string<AIProviderInterface>  $providerClass
     */
    public function register(string $provider, string $providerClass): void
    {
        $this->providers[$provider] = $providerClass;
    }
}
