<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;
use Modules\ProductReviewAI\Infrastructure\AI\AvalAIProvider;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;
use Tests\TestCase;

/**
 * Proves the external source + AI transport log the URL they called and the
 * response they got (both success and failure).
 */
class SourceAndAiLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_digikala_search_logs_the_url_and_response(): void
    {
        config([
            'product_review_ai.sources.digikala.base_url' => 'https://api.digikala.com',
        ]);
        Log::spy();

        Http::fake([
            'api.digikala.com/discovery/api/v2/search*' => Http::response([
                'data' => ['products' => [['id' => 1, 'title_fa' => 'x']]],
            ], 200),
        ]);

        app(DigikalaSource::class)->searchProducts('گوشی', 5);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'ProductReviewAI Digikala request'
                && str_contains($context['url'] ?? '', '/discovery/api/v2/search')
                && array_key_exists('response', $context);
        })->once();
    }

    public function test_avalai_logs_request_url_and_response(): void
    {
        config([
            'product_review_ai.ai.providers.avalai.api_key' => 'test-key',
            'product_review_ai.ai.providers.avalai.base_url' => 'https://api.avalai.ir/v1',
        ]);
        Log::spy();

        Http::fake([
            'api.avalai.ir/*' => Http::response([
                'choices' => [['message' => ['content' => '{"ok":true}']]],
            ], 200),
        ]);

        $content = app(AvalAIProvider::class)->complete('system', 'user');

        $this->assertSame('{"ok":true}', $content);

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'ProductReviewAI AvalAI request'
                && str_contains($context['url'] ?? '', '/chat/completions');
        })->once();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'ProductReviewAI AvalAI response'
                && array_key_exists('response', $context);
        })->once();
    }

    public function test_avalai_without_a_key_fails_loudly(): void
    {
        config(['product_review_ai.ai.providers.avalai.api_key' => '']);

        $this->expectException(IntegrationUnavailableException::class);

        app(AvalAIProvider::class)->complete('system', 'user');
    }
}
