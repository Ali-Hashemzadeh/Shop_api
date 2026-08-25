<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Identity\Domain\Models\User;
use Modules\Review\Domain\Models\Review;
use Tests\TestCase;

/**
 * The 401/403/public matrix on every route (CLAUDE.md §7).
 *
 * Authorization is permission-based: a plain authenticated user without
 * `review.create` gets 403, not 401; every customer role holder may write.
 */
class ReviewAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedReviewPermissions();
    }

    private function createProduct(): Product
    {
        return Product::create([
            'title' => 'محصول مجوز',
            'slug' => 'auth-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    private function createReview(int $userId): Review
    {
        return Review::create([
            'subject_type' => 'product',
            'subject_id' => $this->createProduct()->id,
            'user_id' => $userId,
            'body' => 'مجوز.',
            'status' => 'pending',
        ]);
    }

    // ── POST /api/v1/reviews ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function guests_cannot_create_reviews(): void
    {
        $product = $this->createProduct();

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'مهمان.',
        ])->assertUnauthorized();
    }

    /**
     * @test
     */
    public function a_user_without_the_review_create_permission_is_forbidden_before_validation(): void
    {
        // Authenticated, but holds no role and therefore no `review.create`.
        $bare = User::factory()->create();
        $this->actingAs($bare);

        $product = $this->createProduct();

        // Missing body would be a 422 — proving 403 wins means the permission
        // check runs before validation.
        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
        ])->assertForbidden();
    }

    // ── PATCH /api/v1/reviews/{uuid} ──────────────────────────────────────────

    /**
     * @test
     */
    public function guests_cannot_edit_reviews(): void
    {
        $review = $this->createReview(User::factory()->create()->id);

        $this->patchJson("/api/v1/reviews/{$review->uuid}", ['body' => 'هک.'])
            ->assertUnauthorized();
    }

    // ── GET /api/v1/reviews (public) ──────────────────────────────────────────

    /**
     * @test
     */
    public function the_public_listing_never_requires_authentication(): void
    {
        $product = $this->createProduct();

        $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── Admin surface ─────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function the_admin_index_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/admin/reviews')->assertUnauthorized();

        // Customer has review.create but not view-admin.
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/reviews')
            ->assertForbidden();

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reviews')
            ->assertOk();
    }

    /**
     * @test
     */
    public function moderation_requires_authentication_and_permission(): void
    {
        $review = $this->createReview(User::factory()->create()->id);

        $this->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'approved'])
            ->assertUnauthorized();

        $this->actingAsCustomer();

        $this->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $review->fresh()->status->value);
    }

    /**
     * @test
     */
    public function reply_requires_authentication_and_permission(): void
    {
        $review = $this->createReview(User::factory()->create()->id);

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", ['reply' => 'پاسخ.'])
            ->assertUnauthorized();

        $this->actingAsCustomer();

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", ['reply' => 'پاسخ.'])
            ->assertForbidden();

        $this->assertNull($review->fresh()->seller_reply);
    }
}
