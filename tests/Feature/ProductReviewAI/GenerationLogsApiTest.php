<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Tests\Feature\ProductReviewAI\Concerns\InteractsWithAiReviewPipeline;
use Tests\TestCase;

/**
 * GET /api/v1/admin/ai-reviews/generations/{generation}/logs — pagination,
 * ordering, sanitization, and the elevated diagnostics permission.
 */
class GenerationLogsApiTest extends TestCase
{
    use InteractsWithAiReviewPipeline;
    use RefreshDatabase;

    private const SECRET_KEY = 'aa-LIVEKEY-0123456789abcdef';

    private AiReviewGeneration $generation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPipeline();
        config(['product_review_ai.ai.providers.avalai.api_key' => self::SECRET_KEY]);
        $this->generation = $this->makeGeneration($this->createProduct(), ['status' => 'failed']);
    }

    private function url(string $query = ''): string
    {
        return "/api/v1/admin/ai-reviews/generations/{$this->generation->id}/logs{$query}";
    }

    /**
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    private function log(string $type, ?array $request, ?array $response = null, ?string $error = null, string $at = '2026-11-11 08:00:00'): int
    {
        return DB::table('ai_review_generation_logs')->insertGetId([
            'generation_id' => $this->generation->id,
            'type' => $type,
            'request' => $request !== null ? json_encode($request) : null,
            'response' => $response !== null ? json_encode($response) : null,
            'status' => $error === null ? 'success' : 'failed',
            'error' => $error,
            'created_at' => $at,
        ]);
    }

    public function test_logs_are_chronological_with_a_stable_id_tiebreaker_and_paginated(): void
    {
        $late = $this->log('PROCESSING_COMPLETED', ['stage' => 'finished'], null, null, '2026-11-11 08:05:00');
        $first = $this->log('PROCESSING_STARTED', ['stage' => 'initializing'], null, null, '2026-11-11 08:00:00');
        $second = $this->log('SOURCE_REVIEWS_FETCH', ['external_id' => '123', 'limit' => 100], ['collected' => 42], null, '2026-11-11 08:00:00');
        $this->actingAsAdmin();

        $this->getJson($this->url())->assertOk()->assertJsonPath('meta.per_page', 20);

        $page1 = $this->getJson($this->url('?per_page=2'))->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame([$first, $second], collect($page1->json('data'))->pluck('id')->all());
        $this->assertSame([$late], collect($this->getJson($this->url('?per_page=2&page=2'))->json('data'))->pluck('id')->all());

        $fetch = collect($page1->json('data'))->firstWhere('id', $second);
        $this->assertSame('collecting_reviews', $fetch['stage']);
        $this->assertSame(['external_id' => '123', 'limit' => 100], $fetch['request_summary']);
        $this->assertSame(['collected' => 42], $fetch['response_summary']);
        $this->assertSame($this->generation->id, $fetch['generation_id']);
        $this->assertArrayNotHasKey('diagnostics', $fetch);
    }

    public function test_sensitive_data_is_never_exposed_by_default(): void
    {
        $this->log('AI_ANALYSIS_RESPONSE', [
            'model' => 'deepseek-v4.1-flash',
            'api_key' => self::SECRET_KEY,
            'headers' => ['Authorization' => 'Bearer '.self::SECRET_KEY],
        ], [
            'raw' => 'customer ali@example.com 09121234567 said the camera is great',
            'analysis' => ['positive_points' => ['a', 'b'], 'negative_points' => ['c']],
        ], 'AvalAI returned HTTP 401: {"error":"Incorrect API key provided: '.self::SECRET_KEY.'", "authorization":"Bearer abc.def"}'
            ."\nStack trace:\n#0 /var/www/app/Provider.php(12): call()");
        $this->actingAsAdmin();

        $body = $this->getJson($this->url())->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SECRET_KEY, $body);
        $this->assertStringNotContainsString('ali@example.com', $body);
        $this->assertStringNotContainsString('09121234567', $body);
        $this->assertStringNotContainsString('camera is great', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString('/var/www', $body);
        $this->assertStringNotContainsString('abc.def', $body);

        $entry = $this->getJson($this->url())->json('data.0');
        $this->assertSame('analyzing_reviews', $entry['stage']);
        $this->assertSame(['model' => 'deepseek-v4.1-flash'], $entry['request_summary']);
        $this->assertSame(['analysis' => [
            'positive_points_count' => 2, 'negative_points_count' => 1,
            'customer_profiles_count' => 0, 'important_features_count' => 0,
        ]], $entry['response_summary']);
        $this->assertEqualsCanonicalizing(['request.api_key', 'request.headers', 'response.raw'], $entry['omitted_fields']);
        $this->assertStringContainsString('HTTP 401', $entry['error']);
    }

    public function test_diagnostics_require_the_elevated_permission_and_are_still_redacted(): void
    {
        $this->log('AI_GENERATION_RESPONSE', [
            'api_key' => self::SECRET_KEY,
            'nested' => ['access_token' => 'tok-123', 'note' => 'contact me at bob@example.com'],
        ], ['raw' => 'reply mentioning '.self::SECRET_KEY]);

        $this->actingWithPermissions('product-review-ai.manage');
        $this->getJson($this->url('?diagnostics=1'))->assertStatus(403);
        $this->getJson($this->url())->assertOk(); // the default view still works

        $this->actingWithPermissions('product-review-ai.manage', 'product-review-ai.view-logs');
        $response = $this->getJson($this->url('?diagnostics=1'))->assertOk();

        $diagnostics = $response->json('data.0.diagnostics');
        $this->assertSame('[REDACTED]', $diagnostics['request']['api_key']);
        $this->assertSame('[REDACTED]', $diagnostics['request']['nested']['access_token']);
        $this->assertSame('contact me at [REDACTED_EMAIL]', $diagnostics['request']['nested']['note']);
        $this->assertSame('reply mentioning [REDACTED]', $diagnostics['response']['raw']);
        $this->assertStringNotContainsString(self::SECRET_KEY, $response->getContent());
    }

    public function test_logs_of_a_missing_generation_are_404_and_invalid_paging_is_422(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/ai-reviews/generations/999999/logs')->assertStatus(404);
        $this->getJson($this->url('?per_page=500'))->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_a_real_run_writes_lifecycle_and_stage_logs_that_render_safely(): void
    {
        $product = $this->createProduct();
        $this->mapProduct($product);
        $this->bindSource($this->reviews());
        $this->bindAi([$this->analysisJson(), $this->generationJson()]);
        $this->actingAsAdmin();

        $id = $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate", ['count' => 2])->assertStatus(202)->json('id');

        $types = collect($this->getJson("/api/v1/admin/ai-reviews/generations/{$id}/logs?per_page=100")->assertOk()->json('data'))
            ->pluck('type')->all();

        $this->assertSame([
            'PROCESSING_STARTED', 'SOURCE_REVIEWS_FETCH', 'AI_ANALYSIS_REQUEST', 'AI_ANALYSIS_RESPONSE',
            'AI_GENERATION_REQUEST', 'AI_GENERATION_RESPONSE', 'PROCESSING_COMPLETED',
        ], $types);
    }
}
