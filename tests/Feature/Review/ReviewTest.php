<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Identity\Domain\Models\User;
use Modules\Media\Domain\Models\Media;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Review\Domain\Enums\ReviewStatus;
use Modules\Review\Domain\Models\Review;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedReviewPermissions();
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function createProduct(): Product
    {
        return Product::create([
            'title' => 'محصول تستی',
            'slug' => 'test-product-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    private function createMedia(): Media
    {
        return Media::create([
            'file_path' => 'reviews/'.bin2hex(random_bytes(4)).'.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'original_name' => 'photo.jpg',
        ]);
    }

    /** A realized (paid) order containing the product, so the user is a verified purchaser. */
    private function purchase(User $user, Product $product): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 1000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => [],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'bdv-TEST01',
            'product_title' => $product->title,
            'quantity' => 1,
            'price_per_unit' => 1000,
            'line_total' => 1000,
            'product_snapshot' => ['product_id' => $product->id],
        ]);

        return $order;
    }

    // ── Verified purchaser writes ─────────────────────────────────────────────

    /**
     * @test
     */
    public function a_verified_purchaser_can_submit_rating_body_and_photos(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->purchase($user, $product);
        $media = $this->createMedia();

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'rating' => 5,
            'body' => 'کیفیت عالی بود.',
            'gallery_media_ids' => [$media->id],
        ])
            ->assertCreated()
            ->assertJsonPath('verified_purchase', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('gallery_urls.0', fn ($url) => str_contains((string) $url, '/storage/'));

        $review = Review::query()->sole();
        $this->assertTrue($review->verified_purchase);
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertSame([$media->id], $review->gallery_media_ids);
        $this->assertMatchesRegularExpression('/^bdr-[A-Z0-9]{6}$/', (string) $review->uuid);
    }

    /**
     * @test
     */
    public function a_purchaser_must_supply_a_rating_in_range(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->purchase($user, $product);

        // Required for purchasers.
        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'بدون امتیاز.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');

        // Integer 1–5 only — never a float, never out of range.
        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'rating' => 6,
            'body' => 'بالا از محدوده.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'rating' => 0,
            'body' => 'صفر.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');
    }

    /**
     * @test
     */
    public function gallery_media_ids_must_reference_real_pre_uploaded_media(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $this->purchase($user, $product);

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'rating' => 4,
            'body' => 'تصویر ناموجود.',
            'gallery_media_ids' => [999999],
        ])->assertStatus(422)->assertJsonValidationErrors('gallery_media_ids.0');
    }

    // ── Non-purchaser gating (by validation, not by blocking) ─────────────────

    /**
     * @test
     */
    public function a_non_purchaser_submitting_a_rating_is_rejected_with_422(): void
    {
        $this->actingAsCustomer();
        $product = $this->createProduct();

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'rating' => 5,
            'body' => 'نخریدم ولی امتیاز می‌دهم.',
        ])->assertStatus(422)->assertJsonValidationErrors('rating');

        $this->assertSame(0, Review::count());
    }

    /**
     * @test
     */
    public function a_non_purchaser_submitting_gallery_media_ids_is_rejected_with_422(): void
    {
        $media = $this->createMedia();
        $this->actingAsCustomer();
        $product = $this->createProduct();

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'می‌خواهم عکس بگذارم.',
            'gallery_media_ids' => [$media->id],
        ])->assertStatus(422)->assertJsonValidationErrors('gallery_media_ids');

        $this->assertSame(0, Review::count());
    }

    /**
     * @test
     */
    public function a_non_purchaser_can_leave_a_body_only_comment(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();

        $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'سوالی دارم درباره این محصول.',
        ])
            ->assertCreated()
            ->assertJsonPath('rating', null)
            ->assertJsonPath('verified_purchase', false)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('gallery_urls', []);

        $review = Review::query()->sole();
        $this->assertNull($review->rating);
        $this->assertFalse($review->verified_purchase);
    }

    // ── Uniqueness / upgrade-in-place semantics ───────────────────────────────

    /**
     * @test
     */
    public function a_second_submission_by_the_same_user_for_the_same_subject_updates_in_place(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();

        $first = $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'نسخه اول.',
        ])->assertCreated()->json('id');

        $second = $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'نسخه دوم ویرایش شده.',
        ])->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Review::count());
        $this->assertSame('نسخه دوم ویرایش شده.', (string) Review::query()->sole()->body);
    }

    /**
     * @test
     */
    public function editing_resets_an_approved_review_back_to_pending(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();
        $review = Review::create([
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'user_id' => $user->id,
            'body' => 'اولیه.',
            'status' => 'approved',
        ]);

        $this->patchJson("/api/v1/reviews/{$review->uuid}", ['body' => 'ویرایش شده.'])
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertSame(ReviewStatus::Pending, $review->fresh()->status);
        $this->assertSame('ویرایش شده.', (string) $review->fresh()->body);
    }

    /**
     * @test
     */
    public function a_non_purchaser_who_edits_after_purchasing_can_now_add_rating_and_photos(): void
    {
        $user = $this->actingAsCustomer();
        $product = $this->createProduct();

        // Comment first, purchase later.
        $reviewId = $this->postJson('/api/v1/reviews', [
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'body' => 'کامنت بدون خرید.',
        ])->assertCreated()->json('id');

        $this->assertFalse(Review::query()->where('uuid', $reviewId)->sole()->verified_purchase);

        $this->purchase($user, $product);
        $media = $this->createMedia();

        $this->patchJson("/api/v1/reviews/{$reviewId}", [
            'body' => 'حالا خریدم و امتیاز هم می‌دهم.',
            'rating' => 4,
            'gallery_media_ids' => [$media->id],
        ])
            ->assertOk()
            ->assertJsonPath('verified_purchase', true)
            ->assertJsonPath('rating', 4);

        $review = Review::query()->where('uuid', $reviewId)->sole();
        $this->assertTrue($review->verified_purchase);
        $this->assertSame([$media->id], $review->gallery_media_ids);
        // The edit still needs re-moderation.
        $this->assertSame(ReviewStatus::Pending, $review->status);
        $this->assertSame(1, Review::count());
    }

    // ── Ownership / addressing ────────────────────────────────────────────────

    /**
     * @test
     */
    public function editing_someone_elses_review_is_a_standard_policy_403(): void
    {
        $owner = User::factory()->create();
        $product = $this->createProduct();
        $review = Review::create([
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'user_id' => $owner->id,
            'body' => 'مالکش منم.',
        ]);

        $this->actingAsCustomer();

        $this->patchJson("/api/v1/reviews/{$review->uuid}", ['body' => 'دزدی!'])
            ->assertForbidden();

        $this->assertSame('مالکش منم.', (string) $review->fresh()->body);
    }

    /**
     * @test
     */
    public function editing_an_unknown_review_returns_404(): void
    {
        $this->actingAsCustomer();

        $this->patchJson('/api/v1/reviews/bdr-ZZZZZZ', ['body' => 'هیچی.'])
            ->assertNotFound();
    }

    // ── Public listing ────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function the_public_endpoint_never_serves_pending_or_rejected_reviews_even_if_status_is_passed(): void
    {
        $product = $this->createProduct();
        $approved = Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 1,
            'rating' => 3, 'body' => 'تایید شده.', 'status' => 'approved',
        ]);
        Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 2,
            'rating' => 5, 'body' => 'در انتظار.', 'status' => 'pending',
        ]);
        Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 3,
            'rating' => 1, 'body' => 'رد شده.', 'status' => 'rejected',
        ]);

        $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}&status=pending")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->uuid)
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}&status=rejected")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * @test
     */
    public function the_public_endpoint_supports_sort_newest_highest_and_lowest(): void
    {
        $product = $this->createProduct();

        $low = Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 1,
            'rating' => 1, 'body' => 'بد.', 'status' => 'approved',
        ]);
        $high = Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 2,
            'rating' => 5, 'body' => 'خوب.', 'status' => 'approved',
        ]);
        $unrated = Review::create([
            'subject_type' => 'product', 'subject_id' => $product->id, 'user_id' => 3,
            'body' => 'بی امتیاز.', 'status' => 'approved',
        ]);

        $highest = $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}&sort=highest")
            ->assertOk()->json('data.0.id');
        $this->assertSame($high->uuid, $highest);

        $lowest = $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}&sort=lowest")
            ->assertOk()->json('data.0.id');
        $this->assertSame($low->uuid, $lowest);

        // Unrated rows never land above rated ones in either direction.
        $lowestIds = $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}&sort=lowest")
            ->json('data.*.id');
        $this->assertSame($unrated->uuid, end($lowestIds));

        $this->getJson("/api/v1/reviews?subject_type=product&subject_id={$product->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data'); // newest default
    }
}
