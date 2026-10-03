<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\ProductReviewAI\Domain\Exceptions\ExternalSourceException;
use Modules\ProductReviewAI\Domain\Exceptions\IntegrationUnavailableException;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;
use Tests\TestCase;

/**
 * Exercises the real DigikalaSource against faked HTTP responses — no network,
 * no production stub. This is the parsing contract the module depends on.
 */
class DigikalaSourceTest extends TestCase
{
    use RefreshDatabase;

    private function source(): DigikalaSource
    {
        config([
            'product_review_ai.sources.digikala.base_url' => 'https://api.digikala.com',
            'product_review_ai.sources.digikala.web_base_url' => 'https://www.digikala.com',
        ]);

        return app(DigikalaSource::class);
    }

    public function test_search_parses_products_and_builds_dkp_url(): void
    {
        Http::fake([
            'api.digikala.com/discovery/api/v2/search*' => Http::response([
                'data' => [
                    'products' => [
                        ['id' => 123, 'title_fa' => 'گوشی سامسونگ S25', 'url' => ['uri' => '/product/dkp-123/samsung-s25/']],
                        ['id' => 456, 'title_fa' => 'قاب گوشی'],
                    ],
                ],
            ], 200),
        ]);

        $results = $this->source()->searchProducts('سامسونگ', 5);

        $this->assertCount(2, $results);
        $this->assertSame('123', $results[0]->externalId);
        $this->assertSame('گوشی سامسونگ S25', $results[0]->title);
        $this->assertSame('https://www.digikala.com/product/dkp-123/samsung-s25/', $results[0]->url);
        // No uri in the payload → the id-derived dkp URL.
        $this->assertSame('https://www.digikala.com/product/dkp-456/', $results[1]->url);
    }

    public function test_search_parses_the_nested_widget_v2_layout(): void
    {
        // The real /discovery/api/v2/search response nests each product as a
        // {type:"product", data:{...}} entry inside the listing widget's own
        // data.widgets[]. Filter nodes (which also carry ids) must be ignored.
        Http::fake([
            'api.digikala.com/discovery/api/v2/search*' => Http::response([
                'data' => [
                    'widgets' => [
                        ['type' => 'vertical_product_listing', 'data' => [
                            'filters' => ['brands' => ['top_options' => [['id' => 18, 'code' => 'samsung']]]],
                            'widgets' => [
                                ['type' => 'product', 'data' => ['id' => 17918956, 'title_fa' => 'گوشی سامسونگ S25 Ultra', 'url' => ['uri' => '/product/dkp-17918956/samsung-s25-ultra/']]],
                                ['type' => 'product', 'data' => ['id' => 18341077, 'title_fa' => 'کاور سامسونگ']],
                            ],
                        ]],
                    ],
                ],
            ], 200),
        ]);

        $results = $this->source()->searchProducts('Samsung s25 ultra', 5);

        $this->assertCount(2, $results);
        $this->assertSame('17918956', $results[0]->externalId);
        $this->assertSame('گوشی سامسونگ S25 Ultra', $results[0]->title);
        $this->assertSame('https://www.digikala.com/product/dkp-17918956/samsung-s25-ultra/', $results[0]->url);
        // The brand filter (id 18) must not leak in as a product.
        $this->assertSame('18341077', $results[1]->externalId);
    }

    public function test_get_reviews_parses_comments_and_normalizes_a_dkp_id(): void
    {
        // Real shape: data.comments[] with `rate`/`title`/`body`.
        Http::fake([
            'api.digikala.com/v1/rate-review/products/123/*' => Http::response([
                'data' => [
                    'comments' => [
                        ['rate' => 5, 'title' => 'عالی', 'body' => 'کیفیت فوق‌العاده بود'],
                        ['rate' => 2, 'body' => 'انتظار بیشتری داشتم'],
                        ['rate' => 4, 'body' => '   '], // empty body → skipped
                        ['rate' => 0, 'body' => 'کاش می‌شد بخرم'], // non-buyer, rate 0 → null rating
                    ],
                    'pager' => ['total_pages' => 1],
                ],
            ], 200),
        ]);

        // A dkp-prefixed id must resolve to the numeric product endpoint.
        $reviews = $this->source()->getReviews('dkp-123', 50);

        $this->assertCount(3, $reviews);
        $this->assertSame(5, $reviews[0]->rating);
        $this->assertSame('عالی', $reviews[0]->title);
        $this->assertSame('کیفیت فوق‌العاده بود', $reviews[0]->body);
        $this->assertSame(2, $reviews[1]->rating);
        $this->assertNull($reviews[1]->title);
        // rate 0 (non-buyer) is normalized to a null star rating, body kept.
        $this->assertNull($reviews[2]->rating);
        $this->assertSame('کاش می‌شد بخرم', $reviews[2]->body);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/rate-review/products/123/'));
    }

    public function test_get_reviews_respects_the_limit_across_pages(): void
    {
        Http::fake([
            'api.digikala.com/v1/rate-review/products/999/*' => Http::response([
                'data' => [
                    'comments' => [
                        ['rate' => 5, 'body' => 'یک'],
                        ['rate' => 4, 'body' => 'دو'],
                        ['rate' => 3, 'body' => 'سه'],
                    ],
                    'pager' => ['total_pages' => 10],
                ],
            ], 200),
        ]);

        $reviews = $this->source()->getReviews('999', 2);

        $this->assertCount(2, $reviews);
    }

    public function test_missing_base_url_fails_loudly(): void
    {
        config(['product_review_ai.sources.digikala.base_url' => '']);

        $this->expectException(IntegrationUnavailableException::class);

        app(DigikalaSource::class)->searchProducts('x', 5);
    }

    public function test_a_failed_http_call_raises_an_external_source_exception(): void
    {
        Http::fake([
            'api.digikala.com/*' => Http::response('server error', 500),
        ]);

        $this->expectException(ExternalSourceException::class);

        $this->source()->searchProducts('x', 5);
    }
}
