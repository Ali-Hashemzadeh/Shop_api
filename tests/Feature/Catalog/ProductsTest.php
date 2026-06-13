<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    // ── POST /api/v1/catalog/products ────────────────────────────────────────

    /** @test */
    public function it_can_create_a_product_with_minimal_data(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Wireless Headphones',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'title', 'slug', 'status', 'images', 'variants']);

        $this->assertDatabaseHas('products', ['title' => 'Wireless Headphones']);
    }

    /** @test */
    public function it_auto_generates_a_slug_from_the_title(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'My Awesome Product',
        ]);

        $response->assertCreated()
            ->assertJsonPath('slug', 'my-awesome-product');
    }

    /** @test */
    public function it_defaults_new_products_to_draft_status(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Draft Product',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'draft');
    }

    /** @test */
    public function it_can_create_a_published_product(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title'  => 'Published Product',
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'published');
    }

    /** @test */
    public function it_can_create_a_product_with_a_primary_image_and_gallery(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title'         => 'Camera',
            'primary_image' => UploadedFile::fake()->image('camera-main.jpg'),
            'gallery'       => [
                UploadedFile::fake()->image('camera-angle-1.jpg'),
                UploadedFile::fake()->image('camera-angle-2.jpg'),
            ],
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('primary_image_url'));
        $this->assertCount(2, $response->json('images'));
    }

    /** @test */
    public function it_can_link_a_product_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cameras', 'slug' => 'cameras', 'is_active' => true]);

        $response = $this->postJson('/api/v1/catalog/products', [
            'title'       => 'DSLR Camera',
            'category_id' => $category->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('category_id', $category->id);
    }

    /** @test */
    public function it_requires_a_title_to_create_a_product(): void
    {
        $this->postJson('/api/v1/catalog/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);
    }

    /** @test */
    public function it_rejects_an_invalid_product_status(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title'  => 'Product',
            'status' => 'archived',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_rejects_providing_both_primary_media_id_and_image_file(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title'            => 'Product',
            'primary_media_id' => 1,
            'primary_image'    => UploadedFile::fake()->image('photo.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('primary_media_id');
    }

    // ── GET /api/v1/catalog/products/{id} ────────────────────────────────────

    /** @test */
    public function it_can_fetch_a_published_product_by_id(): void
    {
        $product = Product::create([
            'title'  => 'Smart Watch',
            'slug'   => 'smart-watch',
            'status' => 'published',
        ]);

        $response = $this->getJson("/api/v1/catalog/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('title', 'Smart Watch')
            ->assertJsonPath('status', 'published');
    }

    /** @test */
    public function it_returns_404_for_a_draft_product_on_the_public_endpoint(): void
    {
        $product = Product::create([
            'title'  => 'Unreleased Product',
            'slug'   => 'unreleased-product',
            'status' => 'draft',
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->id}")
            ->assertNotFound();
    }

    /** @test */
    public function it_returns_404_when_product_is_not_found(): void
    {
        $this->getJson('/api/v1/catalog/products/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/products/{id}/admin ──────────────────────────────

    /** @test */
    public function it_returns_draft_products_on_the_admin_endpoint(): void
    {
        $product = Product::create([
            'title'  => 'Draft Product',
            'slug'   => 'draft-product',
            'status' => 'draft',
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->id}/admin")
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('status', 'draft');
    }

    // ── GET /api/v1/catalog/products/slug/{slug} ─────────────────────────────

    /** @test */
    public function it_can_fetch_a_published_product_by_slug(): void
    {
        Product::create(['title' => 'Laptop', 'slug' => 'the-laptop', 'status' => 'published']);

        $this->getJson('/api/v1/catalog/products/slug/the-laptop')
            ->assertOk()
            ->assertJsonPath('slug', 'the-laptop');
    }

    /** @test */
    public function it_returns_404_when_no_product_matches_the_slug(): void
    {
        $this->getJson('/api/v1/catalog/products/slug/does-not-exist')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/categories/{categoryId}/products ─────────────────

    /** @test */
    public function it_can_list_published_products_in_a_category(): void
    {
        $category = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'published', 'category_id' => $category->id]);
        Product::create(['title' => 'Tablet', 'slug' => 'tablet', 'status' => 'published', 'category_id' => $category->id]);
        // Draft should be excluded
        Product::create(['title' => 'Secret', 'slug' => 'secret', 'status' => 'draft', 'category_id' => $category->id]);

        $response = $this->getJson("/api/v1/catalog/categories/{$category->id}/products");

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }
}
