<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\ProductReviewAI\Domain\Enums\DraftStatus;
use Modules\ProductReviewAI\Domain\Models\AiGeneratedReview;
use Modules\ProductReviewAI\Domain\Models\AiReviewGeneration;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ReviewSourceSeeder;
use Tests\TestCase;

/**
 * The whole feature is admin-only (product-review-ai.manage). Unauthenticated →
 * 401; authenticated-without-permission (customer) → 403.
 */
class AuthorizationTest extends TestCase
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

    private function draft(Product $product): AiGeneratedReview
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
            'name' => 'کاربر',
            'rating' => 5,
            'body' => 'خوب',
            'status' => DraftStatus::Pending->value,
        ]);
    }

    public function test_unauthenticated_cannot_generate(): void
    {
        $product = $this->createProduct();

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate")
            ->assertStatus(401);
    }

    public function test_customer_cannot_generate(): void
    {
        $product = $this->createProduct();
        $this->actingAsCustomer();

        $this->postJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generate")
            ->assertStatus(403);
    }

    public function test_customer_cannot_approve(): void
    {
        $product = $this->createProduct();
        $draft = $this->draft($product);
        $this->actingAsCustomer();

        $this->postJson("/api/v1/admin/ai-reviews/{$draft->id}/approve")
            ->assertStatus(403);

        $draft->refresh();
        $this->assertSame(DraftStatus::Pending, $draft->status);
    }

    public function test_customer_cannot_view_generations(): void
    {
        $product = $this->createProduct();
        $this->actingAsCustomer();

        $this->getJson("/api/v1/admin/products/{$product->uuid}/ai-reviews/generations")
            ->assertStatus(403);
    }
}
