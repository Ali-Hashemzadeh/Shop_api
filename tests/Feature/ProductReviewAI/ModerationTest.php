<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Domain\Contracts\ReviewSourceInterface;
use Modules\ProductReviewAI\Domain\DTOs\ExternalProductDTO;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ReviewSourceSeeder;
use Modules\ProductReviewAI\Infrastructure\Sources\DigikalaSource;
use Modules\Review\Domain\Models\Review;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seed(ProductReviewAIPermissionsSeeder::class);
        $this->seed(ReviewSourceSeeder::class);
    }

    private function createProduct(): Product
    {
        return Product::create([
            'title' => 'محصول',
            'slug' => 'p-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    private function pendingDraft(Product $product): AiGeneratedReview
    {
        $generation = AiReviewGeneration::query()->create([
            'product_id' => $product->id,
            'model' => 'deepseek-v4.1-flash',
            'count_requested' => 1,
            'status' => 'completed',
        ]);

        return AiGeneratedReview::query()->create([
            'generation_id' => $generation->id,
            'product_id' => $product->id,
            'name' => 'سارا محمدی',
            'rating' => 4,
            'title' => 'خرید خوب',
            'body' => 'در کل راضی بودم',
            'status' => DraftStatus::Pending->value,
        ]);
    }

    public function test_approval_publishes_a_normal_review_with_provenance(): void
    {
        $product = $this->createProduct();
        $draft = $this->pendingDraft($product);
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/ai-reviews/{$draft->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', DraftStatus::Approved->value);

        $draft->refresh();
        $this->assertSame(DraftStatus::Approved, $draft->status);
        $this->assertNotNull($draft->published_review_id);

        /** @var Review $review */
        $review = Review::query()->findOrFail($draft->published_review_id);
        $this->assertNull($review->user_id);
        $this->assertSame('سارا محمدی', $review->author_name);
        $this->assertSame(4, $review->rating);
        $this->assertSame('approved', $review->status->value);
        $this->assertTrue((bool) $review->is_ai_generated);
        $this->assertSame($draft->generation_id, $review->ai_generation_id);
        $this->assertSame($product->id, (int) $review->subject_id);
    }

    public function test_a_published_ai_review_is_served_as_an_ordinary_review(): void
    {
        $product = $this->createProduct();
        $draft = $this->pendingDraft($product);
        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/ai-reviews/{$draft->id}/approve")->assertOk();

        // Public, unauthenticated storefront listing.
        $response = $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}")
            ->assertOk();

        $first = $response->json('data.0');
        $this->assertSame('سارا محمدی', $first['author_name']);
        $this->assertSame(4, $first['rating']);
        // Provenance is backend-only: the customer shape never exposes it.
        $this->assertArrayNotHasKey('is_ai_generated', $first);
    }

    public function test_editing_a_draft_saves_version_history(): void
    {
        $product = $this->createProduct();
        $draft = $this->pendingDraft($product);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/ai-reviews/{$draft->id}", ['name' => 'رضا', 'rating' => 2])
            ->assertOk()
            ->assertJsonPath('name', 'رضا')
            ->assertJsonPath('rating', 2)
            ->assertJsonPath('status', DraftStatus::Edited->value);

        $this->patchJson("/api/v1/admin/ai-reviews/{$draft->id}", ['body' => 'متن جدید'])->assertOk();

        // One version snapshot per edit; the first preserves the original name.
        $this->assertSame(2, $draft->versions()->count());
        $this->assertDatabaseHas('ai_generated_review_versions', [
            'generated_review_id' => $draft->id,
        ]);
        $first = $draft->versions()->orderBy('id')->first();
        $this->assertSame('سارا محمدی', $first->content['name']);
    }

    public function test_reject_works_and_an_approved_draft_cannot_be_edited_or_rejected(): void
    {
        $product = $this->createProduct();
        $this->actingAsAdmin();

        $draft = $this->pendingDraft($product);
        $this->postJson("/api/v1/admin/ai-reviews/{$draft->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', DraftStatus::Rejected->value);

        // A published draft is frozen.
        $approved = $this->pendingDraft($product);
        $this->postJson("/api/v1/admin/ai-reviews/{$approved->id}/approve")->assertOk();
        $this->patchJson("/api/v1/admin/ai-reviews/{$approved->id}", ['body' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/ai-reviews/{$approved->id}/reject")->assertStatus(422);
    }

    public function test_admin_can_search_then_save_a_mapping(): void
    {
        $product = $this->createProduct();

        $double = new class implements ReviewSourceInterface
        {
            public function code(): string
            {
                return 'digikala';
            }

            public function searchProducts(string $query, int $limit = 5): array
            {
                return [new ExternalProductDTO('555', 'گوشی', 'https://www.digikala.com/product/dkp-555/')];
            }

            public function getProduct(string $externalId): ExternalProductDTO
            {
                return new ExternalProductDTO($externalId, 'گوشی', null);
            }

            public function getReviews(string $externalId, int $limit): array
            {
                return [];
            }
        };
        $this->app->instance(DigikalaSource::class, $double);

        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/search?source_code=digikala&q=گوشی")
            ->assertOk()
            ->assertJsonPath('data.0.external_id', '555');

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/mappings", [
            'source_code' => 'digikala',
            'external_id' => '555',
            'external_url' => 'https://www.digikala.com/product/dkp-555/',
        ])->assertStatus(201)->assertJsonPath('external_id', '555');

        $source = ReviewSource::query()->where('code', 'digikala')->firstOrFail();
        $this->assertDatabaseHas('external_product_mappings', [
            'product_id' => $product->id,
            'source_id' => $source->id,
            'external_id' => '555',
        ]);
    }
}
