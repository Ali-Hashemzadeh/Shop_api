<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->actingAsAdmin();
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
        $this->postJson('/api/v1/catalog/products', ['title' => 'My Awesome Product'])
            ->assertCreated()
            ->assertJsonPath('slug', 'my-awesome-product');
    }

    /** @test */
    public function it_defaults_new_products_to_draft_status(): void
    {
        $this->postJson('/api/v1/catalog/products', ['title' => 'Draft Product'])
            ->assertCreated()
            ->assertJsonPath('status', 'draft');
    }

    /** @test */
    public function it_can_create_a_published_product(): void
    {
        $this->postJson('/api/v1/catalog/products', ['title' => 'Published Product', 'status' => 'published'])
            ->assertCreated()
            ->assertJsonPath('status', 'published');
    }

    /** @test */
    public function it_can_create_a_product_with_a_primary_image_and_gallery(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Camera',
            'primary_image' => UploadedFile::fake()->image('camera-main.jpg'),
            'gallery' => [
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

        $this->postJson('/api/v1/catalog/products', [
            'title' => 'DSLR Camera',
            'category_id' => $category->id,
        ])->assertCreated()
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
        $this->postJson('/api/v1/catalog/products', ['title' => 'Product', 'status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_rejects_providing_both_primary_media_id_and_image_file(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title' => 'Product',
            'primary_media_id' => 1,
            'primary_image' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('primary_media_id');
    }

    // ── PATCH /api/v1/catalog/products/{id} ──────────────────────────────────

    /** @test */
    public function it_can_update_a_product_title(): void
    {
        $product = Product::create(['title' => 'Old Title', 'slug' => 'old-title', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('title', 'New Title');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'New Title']);
    }

    /** @test */
    public function it_can_publish_a_draft_product(): void
    {
        $product = Product::create(['title' => 'Pending', 'slug' => 'pending', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->id}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('status', 'published');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'published']);
    }

    /** @test */
    public function it_rejects_an_invalid_status_on_update(): void
    {
        $product = Product::create(['title' => 'Product', 'slug' => 'product', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->id}", ['status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_allows_patching_with_the_same_slug_the_product_already_has(): void
    {
        $product = Product::create(['title' => 'Product', 'slug' => 'my-prod', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->id}", ['slug' => 'my-prod'])
            ->assertOk();
    }

    /** @test */
    public function it_rejects_updating_a_slug_already_taken_by_another_product(): void
    {
        Product::create(['title' => 'Product A', 'slug' => 'product-a', 'status' => 'draft']);
        $product = Product::create(['title' => 'Product B', 'slug' => 'product-b', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->id}", ['slug' => 'product-a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    /** @test */
    public function it_returns_404_when_updating_a_non_existent_product(): void
    {
        $this->patchJson('/api/v1/catalog/products/99999', ['title' => 'Ghost'])
            ->assertNotFound();
    }

    // ── DELETE /api/v1/catalog/products/{id} ─────────────────────────────────

    /** @test */
    public function it_can_delete_a_product(): void
    {
        $product = Product::create(['title' => 'To Delete', 'slug' => 'to-delete', 'status' => 'draft']);

        $this->deleteJson("/api/v1/catalog/products/{$product->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /** @test */
    public function it_returns_404_when_deleting_a_non_existent_product(): void
    {
        $this->deleteJson('/api/v1/catalog/products/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/products/{id} ────────────────────────────────────

    /** @test */
    public function it_can_fetch_a_published_product_by_id(): void
    {
        $product = Product::create(['title' => 'Smart Watch', 'slug' => 'smart-watch', 'status' => 'published']);

        $this->getJson("/api/v1/catalog/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('title', 'Smart Watch')
            ->assertJsonPath('status', 'published');
    }

    /** @test */
    public function it_returns_404_for_a_draft_product_on_the_public_endpoint(): void
    {
        $product = Product::create(['title' => 'Unreleased', 'slug' => 'unreleased', 'status' => 'draft']);

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
        $product = Product::create(['title' => 'Draft Product', 'slug' => 'draft-product', 'status' => 'draft']);

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
        Product::create(['title' => 'Secret', 'slug' => 'secret', 'status' => 'draft', 'category_id' => $category->id]);

        $response = $this->getJson("/api/v1/catalog/categories/{$category->id}/products");

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertCount(2, $response->json('data'));
    }
}
