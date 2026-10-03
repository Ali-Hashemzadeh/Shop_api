<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Application\Jobs\GenerateProductAIReviewsJob;
use Modules\ProductReviewAI\Domain\Contracts\AIProviderInterface;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Enums\GenerationStatus;
use Modules\ProductReviewAI\Domain\Exceptions\AiGenerationException;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\AiReviewGenerationLog;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use Modules\ProductReviewAI\Domain\Models\ExternalReview;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Modules\ProductReviewAI\Infrastructure\AI\AvalAIProvider;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\AiPromptSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ReviewSourceSeeder;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;
use Tests\TestCase;

class GenerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seed(ProductReviewAIPermissionsSeeder::class);
        $this->seed(ReviewSourceSeeder::class);
        $this->seed(AiPromptSeeder::class);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'title' => 'گوشی تستی',
            'slug' => 'test-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    private function mapProduct(Product $product, string $externalId = '123'): ExternalProductMapping
    {
        $source = ReviewSource::query()->where('code', 'digikala')->firstOrFail();

        return ExternalProductMapping::query()->create([
            'product_id' => $product->id,
            'source_id' => $source->id,
            'external_id' => $externalId,
        ]);
    }

    /**
     * @param  list<ExternalReviewDTO>  $reviews
     */
    private function bindSource(array $reviews): void
    {
        $double = new class($reviews) implements ReviewSourceInterface
        {
            public function __construct(public array $reviews) {}

            public function code(): string
            {
                return 'digikala';
            }

            public function searchProducts(string $query, int $limit = 5): array
            {
                return [new ExternalProductDTO('123', 'match', 'https://example.test/dkp-123/')];
            }

            public function getProduct(string $externalId): ExternalProductDTO
            {
                return new ExternalProductDTO($externalId, 'match', null);
            }

            public function getReviews(string $externalId, int $limit): array
            {
                return $this->reviews;
            }
        };

        $this->app->instance(DigikalaSource::class, $double);
    }

    /**
     * @param  list<string>  $responses  raw completions returned in order
     */
    private function bindAi(array $responses): void
    {
        $double = new class($responses) implements AIProviderInterface
        {
            public int $calls = 0;

            public function __construct(public array $responses) {}

            public function name(): string
            {
                return 'avalai';
            }

            public function model(): string
            {
                return 'deepseek-v4.1-flash';
            }

            public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
            {
                $response = $this->responses[$this->calls] ?? end($this->responses);
                $this->calls++;

                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return (string) $response;
            }
        };

        $this->app->instance(AvalAIProvider::class, $double);
    }

    private function analysisJson(): string
    {
        return json_encode([
            'positive_points' => ['کیفیت ساخت خوب'],
            'negative_points' => ['قیمت بالا'],
            'customer_profiles' => ['کاربر حرفه‌ای'],
            'important_features' => ['دوربین'],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function generationJson(): string
    {
        return json_encode([
            'reviews' => [
                ['name' => 'علی رضایی', 'rating' => 5, 'title' => 'عالی', 'body' => 'خیلی راضی بودم'],
                ['name' => 'مریم احمدی', 'rating' => 3, 'title' => 'متوسط', 'body' => 'بد نبود ولی قیمتش زیاد است'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_admin_generate_queues_the_job(): void
    {
        Queue::fake();

        $product = $this->createProduct();
        $this->mapProduct($product);
        $this->actingAsAdmin();

        $response = $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate", ['count' => 2]);

        $response->assertStatus(202)->assertJsonPath('status', GenerationStatus::Pending->value);

        Queue::assertPushed(GenerateProductAIReviewsJob::class);
        $this->assertDatabaseHas('ai_review_generations', [
            'product_id' => $product->id,
            'count_requested' => 2,
            'model' => 'deepseek-v4.1-flash',
            'prompt_name' => 'review_generation',
        ]);
    }

    public function test_full_pipeline_parses_ai_json_and_saves_drafts(): void
    {
        $product = $this->createProduct();
        $this->mapProduct($product);

        $this->bindSource([
            new ExternalReviewDTO(5, 'خوب', 'کیفیت عالی بود'),
            new ExternalReviewDTO(2, null, 'انتظار بیشتری داشتم'),
        ]);
        $this->bindAi([$this->analysisJson(), $this->generationJson()]);

        $this->actingAsAdmin();

        // sync queue → the job runs inline.
        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate", ['count' => 2])
            ->assertStatus(202);

        $generation = AiReviewGeneration::query()->firstOrFail();
        $this->assertSame(GenerationStatus::Completed, $generation->status);
        $this->assertSame(['کیفیت ساخت خوب'], $generation->analysis_json['positive_points']);

        $this->assertSame(2, ExternalReview::query()->count());

        $drafts = AiGeneratedReview::query()->where('generation_id', $generation->id)->get();
        $this->assertCount(2, $drafts);
        $this->assertEqualsCanonicalizing([5, 3], $drafts->pluck('rating')->all());
        $this->assertTrue($drafts->every(fn ($d) => $d->status === DraftStatus::Pending));

        // Full logging, not failure-only: both AI stages recorded request + response.
        $this->assertTrue(
            AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'AI_ANALYSIS_RESPONSE')->exists()
        );
        $this->assertTrue(
            AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('type', 'AI_GENERATION_RESPONSE')->exists()
        );
    }

    public function test_ai_failure_marks_generation_failed_and_is_logged(): void
    {
        $product = $this->createProduct();
        $this->mapProduct($product);

        $this->bindSource([new ExternalReviewDTO(5, null, 'خوب بود')]);
        $this->bindAi([new AiGenerationException('AvalAI returned HTTP 500')]);

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate")
            ->assertStatus(202);

        $generation = AiReviewGeneration::query()->firstOrFail();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertStringContainsString('HTTP 500', (string) $generation->failure_reason);
        $this->assertSame(0, AiGeneratedReview::query()->count());
        $this->assertTrue(
            AiReviewGenerationLog::query()->where('generation_id', $generation->id)->where('status', 'failed')->exists()
        );
    }

    public function test_generation_without_a_mapping_is_rejected(): void
    {
        $product = $this->createProduct();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate")
            ->assertStatus(422);

        $this->assertSame(0, AiReviewGeneration::query()->count());
    }

    public function test_existing_ai_reviews_return_a_warning_until_confirmed(): void
    {
        $product = $this->createProduct();
        $this->mapProduct($product);

        // Seed an already-approved AI draft for this product.
        $generation = AiReviewGeneration::query()->create([
            'product_id' => $product->id,
            'model' => 'deepseek-v4.1-flash',
            'count_requested' => 1,
            'status' => GenerationStatus::Completed->value,
        ]);
        AiGeneratedReview::query()->create([
            'generation_id' => $generation->id,
            'product_id' => $product->id,
            'name' => 'کاربر',
            'rating' => 5,
            'body' => 'خوب',
            'status' => DraftStatus::Approved->value,
        ]);

        $this->bindSource([new ExternalReviewDTO(5, null, 'خوب')]);
        $this->bindAi([$this->analysisJson(), $this->generationJson()]);
        $this->actingAsAdmin();

        // Without confirmation → warning, no new run.
        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate")
            ->assertOk()
            ->assertJson(['warning' => true, 'existing_ai_reviews' => 1]);

        $this->assertSame(1, AiReviewGeneration::query()->count());

        // With confirmation → proceeds.
        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate", ['confirm' => true])
            ->assertStatus(202);

        $this->assertSame(2, AiReviewGeneration::query()->count());
    }
}
