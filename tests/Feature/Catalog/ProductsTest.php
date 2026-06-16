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

    // ── GET /api/v1/catalog/products (general index + filters + search) ───────

    /** @test */
    public function it_lists_all_published_products(): void
    {
        Product::create(['title' => 'Published A', 'slug' => 'pub-a', 'status' => 'published']);
        Product::create(['title' => 'Published B', 'slug' => 'pub-b', 'status' => 'published']);
        Product::create(['title' => 'Draft C', 'slug' => 'draft-c', 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_returns_empty_when_no_published_products_exist(): void
    {
        Product::create(['title' => 'Draft Only', 'slug' => 'draft-only', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/catalog/products');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /** @test */
    public function it_filters_products_by_category_id(): void
    {
        $tech = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $fashion = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);

        Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'published', 'category_id' => $tech->id]);
        Product::create(['title' => 'Shirt', 'slug' => 'shirt', 'status' => 'published', 'category_id' => $fashion->id]);

        $this->getJson("/api/v1/catalog/products?category_id={$tech->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category_id', $tech->id);
    }

    /** @test */
    public function it_returns_422_for_nonexistent_category_id(): void
    {
        $this->getJson('/api/v1/catalog/products?category_id=99999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    /** @test */
    public function it_filters_products_by_min_price_using_the_default_variant(): void
    {
        $cheap = Product::create(['title' => 'Budget Item', 'slug' => 'budget-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $cheap->id, 'sku' => 'CHEAP-01', 'is_default' => true, 'base_price' => 50000]);

        $expensive = Product::create(['title' => 'Premium Item', 'slug' => 'premium-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $expensive->id, 'sku' => 'PREM-01', 'is_default' => true, 'base_price' => 500000]);

        $this->getJson('/api/v1/catalog/products?min_price=100000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Premium Item');
    }

    /** @test */
    public function it_filters_products_by_max_price_using_the_default_variant(): void
    {
        $cheap = Product::create(['title' => 'Budget Item', 'slug' => 'budget-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $cheap->id, 'sku' => 'CHEAP-01', 'is_default' => true, 'base_price' => 50000]);

        $expensive = Product::create(['title' => 'Premium Item', 'slug' => 'premium-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $expensive->id, 'sku' => 'PREM-01', 'is_default' => true, 'base_price' => 500000]);

        $this->getJson('/api/v1/catalog/products?max_price=100000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Budget Item');
    }

    /** @test */
    public function it_ignores_non_default_variants_when_filtering_by_price(): void
    {
        $product = Product::create(['title' => 'Multi-variant', 'slug' => 'multi-variant', 'status' => 'published']);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'MV-DEFAULT', 'is_default' => true, 'base_price' => 50000]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'MV-OTHER', 'is_default' => false, 'base_price' => 999000]);

        // min_price is above the default variant price — product should NOT appear
        $this->getJson('/api/v1/catalog/products?min_price=100000')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @test */
    public function it_searches_products_by_title(): void
    {
        Product::create(['title' => 'Wireless Headphones', 'slug' => 'wireless-headphones', 'status' => 'published']);
        Product::create(['title' => 'Running Shoes', 'slug' => 'running-shoes', 'status' => 'published']);

        $this->getJson('/api/v1/catalog/products?search=Wireless')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Wireless Headphones');
    }

    /** @test */
    public function it_searches_products_by_description(): void
    {
        Product::create(['title' => 'Laptop A', 'slug' => 'laptop-a', 'status' => 'published', 'description' => 'Great for long distance travel']);
        Product::create(['title' => 'Laptop B', 'slug' => 'laptop-b', 'status' => 'published', 'description' => 'Noise cancelling technology']);

        $this->getJson('/api/v1/catalog/products?search=distance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Laptop A');
    }

    /** @test */
    public function it_applies_combined_filters_correctly(): void
    {
        $tech = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $fashion = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);

        $keyboard = Product::create(['title' => 'Keyboard', 'slug' => 'keyboard', 'status' => 'published', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $keyboard->id, 'sku' => 'KB-01', 'is_default' => true, 'base_price' => 80000]);

        $monitor = Product::create(['title' => 'Monitor', 'slug' => 'monitor', 'status' => 'published', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $monitor->id, 'sku' => 'MON-01', 'is_default' => true, 'base_price' => 300000]);

        $shirt = Product::create(['title' => 'Keyboard Shirt', 'slug' => 'keyboard-shirt', 'status' => 'published', 'category_id' => $fashion->id]);
        ProductVariant::create(['product_id' => $shirt->id, 'sku' => 'SHIRT-01', 'is_default' => true, 'base_price' => 80000]);

        // category=tech + max_price=100000 + search=Key → only Keyboard matches all three
        $this->getJson("/api/v1/catalog/products?category_id={$tech->id}&max_price=100000&search=Key")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Keyboard');
    }
}
