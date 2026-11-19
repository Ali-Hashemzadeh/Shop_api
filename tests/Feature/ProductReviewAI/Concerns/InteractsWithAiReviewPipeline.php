<?php

namespace Tests\Feature\ProductReviewAI\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Domain\Models\Product;
use Modules\Identity\Domain\Models\User;
use Modules\ProductReviewAI\Domain\Contracts\AIProviderInterface;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\DTOs\ExternalReviewDTO;
use Modules\ProductReviewAI\Domain\DTOs\GenerationRequestContext;
use Modules\ProductReviewAI\Domain\Models\AiPrompt;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ExternalProductMapping;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Modules\ProductReviewAI\Infrastructure\AI\AvalAIProvider;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\AiPromptSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ReviewSourceSeeder;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;

/**
 * Shared fixtures for the generation monitoring / lifecycle / retry / stuck
 * tests. External integrations are always test doubles bound in the container.
 */
trait InteractsWithAiReviewPipeline
{
    protected function seedPipeline(): void
    {
        $this->seedIdentityRolesAndPermissions();
        $this->seed(ProductReviewAIPermissionsSeeder::class);
        $this->seed(ReviewSourceSeeder::class);
        $this->seed(AiPromptSeeder::class);
    }

    protected function createProduct(string $title = 'گوشی تستی'): Product
    {
        return Product::create([
            'title' => $title,
            'slug' => 'test-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    protected function digikala(): ReviewSource
    {
        return ReviewSource::query()->where('code', 'digikala')->firstOrFail();
    }

    protected function mapProduct(Product $product, string $externalId = '123'): ExternalProductMapping
    {
        return ExternalProductMapping::query()->updateOrCreate(
            ['product_id' => $product->id, 'source_id' => $this->digikala()->id],
            ['external_id' => $externalId],
        );
    }

    /** A user holding only the listed permissions (no role). */
    protected function actingWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Insert a generation row with arbitrary (including non-fillable and
     * timestamp) columns, bypassing Eloquent so tests control every value.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeGeneration(Product $product, array $attributes = []): AiReviewGeneration
    {
        $now = Carbon::now();

        $row = array_merge([
            'product_id' => $product->id,
            'source_id' => $this->digikala()->id,
            'model' => 'deepseek-v4.1-flash',
            'count_requested' => 2,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes);

        foreach (['analysis_json', 'request_context'] as $json) {
            if (isset($row[$json]) && is_array($row[$json])) {
                $row[$json] = json_encode($row[$json]);
            }
        }

        $id = DB::table('ai_review_generations')->insertGetId($row);

        return AiReviewGeneration::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contextFor(Product $product, ExternalProductMapping $mapping, int $count = 2): array
    {
        $analysis = AiPrompt::query()->where('name', 'review_analysis')->where('active', true)->orderByDesc('version')->firstOrFail();
        $generation = AiPrompt::query()->where('name', 'review_generation')->where('active', true)->orderByDesc('version')->firstOrFail();

        return (new GenerationRequestContext(
            productId: $product->id,
            productUuid: $product->uuid,
            productTitle: $product->title,
            productDescription: null,
            sourceId: $this->digikala()->id,
            sourceCode: 'digikala',
            mappingId: $mapping->id,
            externalId: (string) $mapping->external_id,
            count: $count,
            aiProvider: 'avalai',
            aiModel: 'deepseek-v4.1-flash',
            analysisPromptName: $analysis->name,
            analysisPromptVersion: (int) $analysis->version,
            generationPromptName: $generation->name,
            generationPromptVersion: (int) $generation->version,
            reviewLimit: 100,
        ))->toArray();
    }

    /**
     * @param  list<ExternalReviewDTO>|\Throwable  $reviews
     * @param  (callable(): void)|null  $onFetch  runs inside getReviews (e.g. to observe the stage)
     */
    protected function bindSource(array|\Throwable $reviews, ?callable $onFetch = null): object
    {
        $double = new class($reviews, $onFetch) implements ReviewSourceInterface
        {
            public int $calls = 0;

            public function __construct(public array|\Throwable $reviews, public $onFetch) {}

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
                $this->calls++;

                if ($this->onFetch !== null) {
                    ($this->onFetch)();
                }

                if ($this->reviews instanceof \Throwable) {
                    throw $this->reviews;
                }

                return $this->reviews;
            }
        };

        $this->app->instance(DigikalaSource::class, $double);

        return $double;
    }

    /**
     * @param  list<string|\Throwable|callable>  $responses  completions returned in order;
     *                                                       a callable is invoked and its result used
     */
    protected function bindAi(array $responses): object
    {
        $double = new class($responses) implements AIProviderInterface
        {
            public int $calls = 0;

            /** @var list<array<string, mixed>> */
            public array $options = [];

            public function __construct(public array $responses) {}

            public function name(): string
            {
                return 'avalai';
            }

            public function model(): string
            {
                return (string) config('product_review_ai.ai.providers.avalai.model');
            }

            public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
            {
                $this->options[] = $options;
                $response = $this->responses[$this->calls] ?? end($this->responses);
                $this->calls++;

                if (is_callable($response)) {
                    $response = $response();
                }

                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return (string) $response;
            }
        };

        $this->app->instance(AvalAIProvider::class, $double);

        return $double;
    }

    protected function reviews(): array
    {
        return [
            new ExternalReviewDTO(5, 'خوب', 'کیفیت عالی بود'),
            new ExternalReviewDTO(2, null, 'انتظار بیشتری داشتم'),
        ];
    }

    protected function analysisJson(): string
    {
        return json_encode([
            'positive_points' => ['کیفیت ساخت خوب'],
            'negative_points' => ['قیمت بالا'],
            'customer_profiles' => ['کاربر حرفه‌ای'],
            'important_features' => ['دوربین'],
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function generationJson(): string
    {
        return json_encode([
            'reviews' => [
                ['name' => 'علی رضایی', 'rating' => 5, 'title' => 'عالی', 'body' => 'خیلی راضی بودم'],
                ['name' => 'مریم احمدی', 'rating' => 3, 'title' => 'متوسط', 'body' => 'بد نبود ولی قیمتش زیاد است'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }
}
