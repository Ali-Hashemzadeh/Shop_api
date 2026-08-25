<?php

namespace Tests\Feature\Review;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Review\Domain\Models\Review;
use Tests\TestCase;

/**
 * The `sales_count` pattern applied to ratings: Review aggregates its own
 * tables (approved + rated rows only) and pushes absolute tallies through
 * CatalogManagerInterface::syncRatingSummary(); Catalog derives
 * `rating_average` at read time and exposes sort=rating / min_rating.
 */
class ReviewRatingSyncTest extends TestCase
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
            'title' => 'محصول امتیاز',
            'slug' => 'rating-'.bin2hex(random_bytes(4)),
            'status' => 'published',
        ]);
    }

    private function review(Product $product, int $userId, ?int $rating, string $status): Review
    {
        return Review::create([
            'subject_type' => 'product',
            'subject_id' => $product->id,
            'user_id' => $userId,
            'rating' => $rating,
            'body' => 'بررسی امتیاز.',
            'status' => $status,
        ]);
    }

    /**
     * @test
     */
    public function only_approved_and_rated_reviews_feed_the_summary(): void
    {
        $product = $this->createProduct();

        $this->review($product, 1, 5, 'approved');
        $this->review($product, 2, 3, 'approved');
        // Approved but unrated — a comment, not a rating. Must never count.
        $this->review($product, 3, null, 'approved');
        // Wrong status — never counts.
        $this->review($product, 4, 5, 'pending');
        $this->review($product, 5, 1, 'rejected');

        $this->artisan('reviews:sync-product-ratings')->assertSuccessful();

        $product->refresh();
        $this->assertSame(8, $product->rating_sum);
        $this->assertSame(2, $product->rating_count);
    }

    /**
     * @test
     */
    public function a_fully_rejected_product_is_synced_back_to_zero(): void
    {
        $product = $this->createProduct();
        $this->review($product, 1, 5, 'approved');

        $this->artisan('reviews:sync-product-ratings')->assertSuccessful();
        $product->refresh();
        $this->assertSame(1, $product->rating_count);

        Review::query()->where('subject_id', $product->id)->update(['status' => 'rejected']);

        $this->artisan('reviews:sync-product-ratings')->assertSuccessful();
        $product->refresh();
        $this->assertSame(0, $product->rating_sum);
        $this->assertSame(0, $product->rating_count);
    }

    /**
     * @test
     */
    public function product_reads_expose_the_derived_average_and_count(): void
    {
        $product = $this->createProduct();
        $this->review($product, 1, 5, 'approved');
        $this->review($product, 2, 4, 'approved');
        // Approved comment without rating: "4.5★ (2 ratings) · 3 reviews" is correct.
        $this->review($product, 3, null, 'approved');

        $this->artisan('reviews:sync-product-ratings')->assertSuccessful();

        $this->getJson("/api/v1/catalog/products/{$product->fresh()->uuid}")
            ->assertOk()
            ->assertJsonPath('rating_average', 4.5)
            ->assertJsonPath('rating_count', 2);
    }

    /**
     * @test
     */
    public function unrated_products_report_a_null_average(): void
    {
        $product = $this->createProduct();

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('rating_average', null)
            ->assertJsonPath('rating_count', 0);
    }

    /**
     * @test
     */
    public function product_reads_expose_the_numeric_id_needed_for_review_subject_id(): void
    {
        $product = $this->createProduct();

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('product_id', $product->id);
    }

    /**
     * @test
     */
    public function products_can_be_sorted_by_rating_and_filtered_by_min_rating(): void
    {
        $great = $this->createProduct();
        $ok = $this->createProduct();
        $unrated = $this->createProduct();

        foreach ([[5, 5], [4, 4]] as [$rating, $userId]) {
            $this->review($great, $userId, $rating, 'approved');
        }
        $this->review($ok, 9, 3, 'approved');

        $this->artisan('reviews:sync-product-ratings')->assertSuccessful();

        $order = $this->getJson('/api/v1/catalog/products?sort=rating&per_page=10')
            ->assertOk()
            ->json('data.*.id');
        $positionOf = fn (string $uuid): int => (int) array_search($uuid, $order, true);
        $this->assertTrue(
            $positionOf($great->fresh()->uuid) < $positionOf($ok->fresh()->uuid),
            'Higher-rated product must sort first.',
        );
        // Unrated never leads the list.
        $this->assertNotSame($unrated->fresh()->uuid, $order[0]);

        // min_rating excludes everything below the threshold and all unrated.
        $filtered = $this->getJson('/api/v1/catalog/products?min_rating=4&per_page=10')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($great->fresh()->uuid, $filtered);
        $this->assertNotContains($ok->fresh()->uuid, $filtered);
        $this->assertNotContains($unrated->fresh()->uuid, $filtered);

        // The admin listing supports both too.
        $this->actingAsAdmin();
        $this->seedCatalogPermissions();
        $adminOrder = $this->getJson('/api/v1/catalog/products/admin?sort=rating&min_rating=4')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($great->fresh()->uuid, $adminOrder);
    }

    /**
     * @test
     */
    public function invalid_sort_and_min_rating_values_are_rejected(): void
    {
        $this->getJson('/api/v1/catalog/products?sort=rating_desc')->assertStatus(422);
        $this->getJson('/api/v1/catalog/products?min_rating=6')->assertStatus(422);
        $this->getJson('/api/v1/catalog/products?min_rating=zero')->assertStatus(422);
    }
}
