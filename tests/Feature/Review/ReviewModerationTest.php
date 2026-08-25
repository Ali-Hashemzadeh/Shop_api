<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Models\Product;
use Modules\Identity\Domain\Models\User;
use Modules\Review\Domain\Models\Review;
use Tests\TestCase;

class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedReviewPermissions();
    }

    private function createReview(string $status = 'pending'): Review
    {
        $product = Product::create([
            'title' => 'محصول',
            'slug' => 'mod-'.Str::random(8),
            'status' => 'published',
        ]);

        return Review::create([
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'rating' => 4,
            'body' => 'برای تعدیل.',
            'status' => $status,
        ]);
    }

    // ── Moderation transitions ────────────────────────────────────────────────

    /**
     * @test
     */
    public function an_admin_can_approve_a_pending_review(): void
    {
        $review = $this->createReview('pending');

        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $review->fresh()->status->value);
    }

    /**
     * @test
     */
    public function an_admin_can_reject_a_pending_review(): void
    {
        $review = $this->createReview('pending');

        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    /**
     * @test
     */
    public function approved_and_rejected_may_be_swapped_for_rereview(): void
    {
        $approved = $this->createReview('approved');

        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/reviews/{$approved->uuid}/status", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame('rejected', $approved->fresh()->status->value);

        $this->patchJson("/api/v1/admin/reviews/{$approved->uuid}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    /**
     * @test
     */
    public function nothing_transitions_into_pending_via_the_admin_endpoint(): void
    {
        foreach (['pending', 'approved', 'rejected'] as $from) {
            $review = $this->createReview($from);

            $this->actingAsAdmin();

            $this->patchJson("/api/v1/admin/reviews/{$review->uuid}/status", ['status' => 'pending'])
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');

            $this->assertSame($from, $review->fresh()->status->value, "A {$from} review must not change via pending.");
        }
    }

    /**
     * @test
     */
    public function moderation_of_an_unknown_review_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/reviews/bdr-ZZZZZZ/status', ['status' => 'approved'])
            ->assertNotFound();
    }

    // ── Seller reply ──────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function an_admin_reply_sets_the_reply_and_timestamp(): void
    {
        $review = $this->createReview('approved');

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", ['reply' => 'سپاس از بازخورد شما.'])
            ->assertOk()
            ->assertJsonPath('seller_reply', 'سپاس از بازخورد شما.')
            ->assertJsonPath('seller_reply_at', fn ($at) => $at !== null);

        $this->assertNotNull($review->fresh()->seller_reply_at);
    }

    /**
     * @test
     */
    public function a_reply_is_overwritable_with_no_thread(): void
    {
        $review = $this->createReview('approved');

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", ['reply' => 'پاسخ اول.'])
            ->assertOk();

        $firstAt = $review->fresh()->seller_reply_at;

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", ['reply' => 'پاسخ جایگزین.'])
            ->assertOk()
            ->assertJsonPath('seller_reply', 'پاسخ جایگزین.');

        $this->assertSame('پاسخ جایگزین.', (string) $review->fresh()->seller_reply);
        $this->assertTrue($review->fresh()->seller_reply_at->greaterThanOrEqualTo($firstAt));
    }

    /**
     * @test
     */
    public function reply_requires_a_body(): void
    {
        $review = $this->createReview('approved');

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/reviews/{$review->uuid}/reply", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reply');
    }

    // ── Admin index ───────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function the_admin_index_shows_every_status_and_filters_by_status_and_subject_type(): void
    {
        $pending = $this->createReview('pending');
        $approved = $this->createReview('approved');
        $this->createReview('rejected');

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/reviews')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->getJson('/api/v1/admin/reviews?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->uuid);

        $this->getJson('/api/v1/admin/reviews?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->uuid);

        // Unknown subject type filter is rejected by validation, never leaked.
        $this->getJson('/api/v1/admin/reviews?subject_type=blog_post')
            ->assertStatus(422);
    }
}
