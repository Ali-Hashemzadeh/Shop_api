<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductImage;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class ProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->actingAsAdmin();
    }

    // ── POST /api/v1/catalog/products ────────────────────────────────────────

    /** @test */
    public function it_can_create_a_product_with_minimal_data(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', ['title' => 'Wireless Headphones']);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'title', 'slug', 'status', 'images', 'variants']);

        $this->assertDatabaseHas('products', ['title' => 'Wireless Headphones']);
    }

    /** @test */
    public function it_exposes_the_uuid_as_the_public_id_not_the_integer(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', ['title' => 'UUID Product'])
            ->assertCreated();

        $product = Product::where('title', 'UUID Product')->firstOrFail();

        // The response `id` is the opaque public code, never the internal integer primary key.
        $response->assertJsonPath('id', $product->uuid);
        $this->assertMatchesRegularExpression('/^bdp-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $response->json('id'));
        $this->assertNotSame((string) $product->id, $response->json('id'));
    }

    /** @test */
    public function it_exposes_available_stock_per_variant_on_the_product_resource(): void
    {
        $product = Product::create(['title' => 'Stocked', 'slug' => 'stocked', 'status' => 'published']);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'STOCK-1',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
            'attributes' => [],
        ]);

        // 20 physical − 5 reserved = 15 available.
        InventoryStock::create(['sku' => 'STOCK-1', 'quantity' => 20, 'reserved_quantity' => 5]);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('variants.0.sku', 'STOCK-1')
            ->assertJsonPath('variants.0.stock', 15);
    }

    /** @test */
    public function it_reports_zero_stock_for_a_variant_with_no_inventory_record(): void
    {
        $product = Product::create(['title' => 'Unstocked', 'slug' => 'unstocked', 'status' => 'published']);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'NOSTOCK-1',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
            'attributes' => [],
        ]);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('variants.0.stock', 0);
    }

    /** @test */
    public function it_404s_when_fetching_a_product_by_its_integer_id(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);

        // The public show route is UUID-constrained; the integer id no longer resolves.
        $this->getJson("/api/v1/catalog/products/{$product->id}")->assertNotFound();
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
    public function it_can_link_a_product_to_a_category(): void
    {
        $category = Category::create(['name' => 'Cameras', 'slug' => 'cameras', 'is_active' => true]);

        $this->postJson('/api/v1/catalog/products', ['title' => 'DSLR Camera', 'category_id' => $category->id])
            ->assertCreated()
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
    public function test_can_create_product_with_nested_variants_atomically(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Laptop Pro',
            'status' => 'published',
            'variants' => [
                ['type' => 'color', 'base_price' => 50000000, 'compare_at_price' => 55000000, 'is_default' => true,  'attributes' => ['color' => 'Black']],
                ['type' => 'color', 'base_price' => 65000000, 'compare_at_price' => null,     'is_default' => false, 'attributes' => ['color' => 'Silver']],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'title', 'variants'])
            ->assertJsonCount(2, 'variants');

        // Response `id` is the product's public code; each variant carries its own
        // independently generated `bdv-` SKU (no positional / id-derived formula).
        $productId = Product::where('uuid', $response->json('id'))->value('id');
        $this->assertDatabaseHas('products', ['title' => 'Laptop Pro']);

        $skus = ProductVariant::where('product_id', $productId)->orderBy('id')->pluck('sku')->all();
        $this->assertCount(2, $skus);
        $this->assertCount(2, array_unique($skus));

        foreach ($skus as $sku) {
            $this->assertMatchesRegularExpression('/^bdv-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $sku);
        }

        $this->assertDatabaseHas('product_variants', ['sku' => $skus[0], 'type' => 'color', 'is_default' => 1]);
        $this->assertDatabaseHas('product_variants', ['sku' => $skus[1], 'type' => 'color', 'is_default' => 0]);
    }

    /** @test */
    public function it_rejects_nested_variants_when_none_is_marked_default(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title' => 'Bad Product',
            'variants' => [
                ['type' => 'color', 'base_price' => 10000, 'is_default' => false],
                ['type' => 'color', 'base_price' => 20000, 'is_default' => false],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants']);

        $this->assertDatabaseMissing('products', ['title' => 'Bad Product']);
    }

    /** @test */
    public function it_rejects_nested_variants_when_multiple_are_marked_default(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title' => 'Bad Product',
            'variants' => [
                ['type' => 'color', 'base_price' => 10000, 'is_default' => true],
                ['type' => 'color', 'base_price' => 20000, 'is_default' => true],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants']);

        $this->assertDatabaseMissing('products', ['title' => 'Bad Product']);
    }

    /** @test */
    public function it_rejects_nested_variants_missing_type(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title' => 'Bad Product',
            'variants' => [
                ['base_price' => 10000, 'is_default' => true],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants.0.type']);
    }

    // ── PATCH /api/v1/catalog/products/{id} ──────────────────────────────────

    /** @test */
    public function it_can_update_a_product_title(): void
    {
        $product = Product::create(['title' => 'Old Title', 'slug' => 'old-title', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('title', 'New Title');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'New Title']);
    }

    /** @test */
    public function it_can_publish_a_draft_product(): void
    {
        $product = Product::create(['title' => 'Pending', 'slug' => 'pending', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('status', 'published');
    }

    /** @test */
    public function it_rejects_an_invalid_status_on_update(): void
    {
        $product = Product::create(['title' => 'Product', 'slug' => 'product', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['status' => 'archived'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_allows_patching_with_the_same_slug_the_product_already_has(): void
    {
        $product = Product::create(['title' => 'Product', 'slug' => 'my-prod', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['slug' => 'my-prod'])->assertOk();
    }

    /** @test */
    public function it_rejects_updating_a_slug_already_taken_by_another_product(): void
    {
        Product::create(['title' => 'Product A', 'slug' => 'product-a', 'status' => 'draft']);
        $product = Product::create(['title' => 'Product B', 'slug' => 'product-b', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['slug' => 'product-a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    /** @test */
    public function it_returns_404_when_updating_a_non_existent_product(): void
    {
        $this->patchJson('/api/v1/catalog/products/99999', ['title' => 'Ghost'])->assertNotFound();
    }

    /** @test */
    public function it_adds_new_gallery_images_when_updating_a_product(): void
    {
        $product = Product::create(['title' => 'Camera', 'slug' => 'camera', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'gallery_media_ids' => [701, 702],
        ])->assertOk()
            ->assertJsonCount(2, 'images');

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'media_id' => 701,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'media_id' => 702,
            'sort_order' => 1,
        ]);
    }

    /** @test */
    public function it_does_not_duplicate_existing_gallery_images_submitted_on_update(): void
    {
        $product = Product::create(['title' => 'Camera', 'slug' => 'camera', 'status' => 'draft']);
        ProductImage::create(['product_id' => $product->id, 'media_id' => 801, 'sort_order' => 0]);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'gallery_media_ids' => [801, 802, 802],
        ])->assertOk()
            ->assertJsonCount(2, 'images');

        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('media_id', 801)->count());
        $this->assertSame(1, ProductImage::where('product_id', $product->id)->where('media_id', 802)->count());
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'media_id' => 802,
            'sort_order' => 1,
        ]);
    }

    /** @test */
    public function omitting_gallery_media_ids_on_update_leaves_the_gallery_unchanged(): void
    {
        $product = Product::create(['title' => 'Camera', 'slug' => 'camera', 'status' => 'draft']);
        $image = ProductImage::create(['product_id' => $product->id, 'media_id' => 901, 'sort_order' => 3]);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['title' => 'Camera Pro'])
            ->assertOk()
            ->assertJsonPath('title', 'Camera Pro')
            ->assertJsonCount(1, 'images');

        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'product_id' => $product->id,
            'media_id' => 901,
            'sort_order' => 3,
        ]);
    }

    /** @test */
    public function gallery_updates_preserve_normal_product_and_variant_updates(): void
    {
        $product = Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'draft']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'PHONE-01',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 10000000,
        ]);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'title' => 'Phone Pro',
            'gallery_media_ids' => [1001],
            'variants' => [[
                'id' => $variant->id,
                'type' => 'color',
                'base_price' => 12000000,
                'is_default' => true,
                'attributes' => ['color' => 'Black'],
            ]],
        ])->assertOk()
            ->assertJsonPath('title', 'Phone Pro')
            ->assertJsonPath('variants.0.base_price', 12000000)
            ->assertJsonCount(1, 'images');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'Phone Pro']);
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'sku' => 'PHONE-01',
            'base_price' => 12000000,
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'media_id' => 1001,
            'sort_order' => 0,
        ]);
    }

    /** @test */
    public function test_can_update_product_variants_with_upsert_by_id(): void
    {
        $product = Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'draft']);
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'bdp'.$product->id.'-v1', 'type' => 'color', 'is_default' => true, 'base_price' => 10000000]);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'variants' => [
                ['id' => $variant->id, 'type' => 'color', 'base_price' => 12000000, 'is_default' => true,  'attributes' => []],
                ['type' => 'color', 'base_price' => 11000000, 'is_default' => false, 'attributes' => ['color' => 'White']],
            ],
        ])->assertOk()->assertJsonCount(2, 'variants');

        // The pre-existing variant was seeded with a legacy-format SKU: updating it
        // must never regenerate that value, because Inventory / Cart / order items
        // all key off it.
        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'sku' => 'bdp'.$product->id.'-v1',
            'base_price' => 12000000,
            'is_default' => 1,
        ]);

        $created = ProductVariant::where('product_id', $product->id)
            ->where('id', '!=', $variant->id)
            ->sole();

        $this->assertMatchesRegularExpression('/^bdv-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $created->sku);
        $this->assertSame(11000000, $created->base_price);
        $this->assertFalse($created->is_default);
    }

    /** @test */
    public function test_omitting_variants_on_update_leaves_existing_variants_untouched(): void
    {
        $product = Product::create(['title' => 'Tablet', 'slug' => 'tablet', 'status' => 'draft']);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'TAB-01', 'type' => 'color', 'is_default' => true, 'base_price' => 5000000]);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", ['title' => 'Tablet Pro'])
            ->assertOk()
            ->assertJsonPath('title', 'Tablet Pro')
            ->assertJsonCount(1, 'variants');

        $this->assertDatabaseHas('product_variants', ['sku' => 'TAB-01', 'base_price' => 5000000]);
    }

    /** @test */
    public function it_rejects_update_variants_when_multiple_are_marked_default(): void
    {
        $product = Product::create(['title' => 'Watch', 'slug' => 'watch', 'status' => 'draft']);

        $this->patchJson("/api/v1/catalog/products/{$product->uuid}", [
            'variants' => [
                ['type' => 'color', 'base_price' => 5000000, 'is_default' => true,  'attributes' => []],
                ['type' => 'color', 'base_price' => 5000000, 'is_default' => true,  'attributes' => []],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variants']);

        $this->assertDatabaseMissing('product_variants', ['product_id' => $product->id]);
    }

    // ── DELETE /api/v1/catalog/products/{id} ─────────────────────────────────

    /** @test */
    public function it_can_delete_a_product(): void
    {
        $product = Product::create(['title' => 'To Delete', 'slug' => 'to-delete', 'status' => 'draft']);

        $this->deleteJson("/api/v1/catalog/products/{$product->uuid}")->assertNoContent();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    /** @test */
    public function it_returns_404_when_deleting_a_non_existent_product(): void
    {
        $this->deleteJson('/api/v1/catalog/products/99999')->assertNotFound();
    }

    // ── GET /api/v1/catalog/products ─────────────────────────────────────────

    /** @test */
    public function it_can_fetch_a_published_product_by_id(): void
    {
        $product = Product::create(['title' => 'Smart Watch', 'slug' => 'smart-watch', 'status' => 'published']);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")
            ->assertOk()
            ->assertJsonPath('id', $product->uuid)
            ->assertJsonPath('title', 'Smart Watch');
    }

    /** @test */
    public function it_returns_404_for_a_draft_product_on_the_public_endpoint(): void
    {
        $product = Product::create(['title' => 'Unreleased', 'slug' => 'unreleased', 'status' => 'draft']);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}")->assertNotFound();
    }

    /** @test */
    public function it_returns_404_when_product_is_not_found(): void
    {
        $this->getJson('/api/v1/catalog/products/99999')->assertNotFound();
    }

    /** @test */
    public function it_returns_draft_products_on_the_admin_endpoint(): void
    {
        $product = Product::create(['title' => 'Draft Product', 'slug' => 'draft-product', 'status' => 'draft']);

        $this->getJson("/api/v1/catalog/products/{$product->uuid}/admin")
            ->assertOk()
            ->assertJsonPath('status', 'draft');
    }

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
        $this->getJson('/api/v1/catalog/products/slug/does-not-exist')->assertNotFound();
    }

    /** @test */
    public function it_can_list_published_products_in_a_category(): void
    {
        $category = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        Product::create(['title' => 'Phone',  'slug' => 'phone',  'status' => 'published', 'category_id' => $category->id]);
        Product::create(['title' => 'Tablet', 'slug' => 'tablet', 'status' => 'published', 'category_id' => $category->id]);
        Product::create(['title' => 'Secret', 'slug' => 'secret', 'status' => 'draft',     'category_id' => $category->id]);

        $this->getJson("/api/v1/catalog/categories/{$category->id}/products")
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertCount(2, $this->getJson("/api/v1/catalog/categories/{$category->id}/products")->json('data'));
    }

    /** @test */
    public function it_lists_all_published_products(): void
    {
        Product::create(['title' => 'Published A', 'slug' => 'pub-a',   'status' => 'published']);
        Product::create(['title' => 'Published B', 'slug' => 'pub-b',   'status' => 'published']);
        Product::create(['title' => 'Draft C',     'slug' => 'draft-c', 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function it_returns_empty_when_no_published_products_exist(): void
    {
        Product::create(['title' => 'Draft Only', 'slug' => 'draft-only', 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /** @test */
    public function it_filters_products_by_category_id(): void
    {
        $tech = Category::create(['name' => 'Tech',    'slug' => 'tech',    'is_active' => true]);
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
        $cheap = Product::create(['title' => 'Budget Item',  'slug' => 'budget-item',  'status' => 'published']);
        ProductVariant::create(['product_id' => $cheap->id,     'sku' => 'CHEAP-01', 'type' => 'color', 'is_default' => true, 'base_price' => 50000]);

        $expensive = Product::create(['title' => 'Premium Item', 'slug' => 'premium-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $expensive->id, 'sku' => 'PREM-01',  'type' => 'color', 'is_default' => true, 'base_price' => 500000]);

        $this->getJson('/api/v1/catalog/products?min_price=100000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Premium Item');
    }

    /** @test */
    public function it_filters_products_by_max_price_using_the_default_variant(): void
    {
        $cheap = Product::create(['title' => 'Budget Item',  'slug' => 'budget-item',  'status' => 'published']);
        ProductVariant::create(['product_id' => $cheap->id,     'sku' => 'CHEAP-01', 'type' => 'color', 'is_default' => true, 'base_price' => 50000]);

        $expensive = Product::create(['title' => 'Premium Item', 'slug' => 'premium-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $expensive->id, 'sku' => 'PREM-01',  'type' => 'color', 'is_default' => true, 'base_price' => 500000]);

        $this->getJson('/api/v1/catalog/products?max_price=100000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Budget Item');
    }

    /** @test */
    public function it_ignores_non_default_variants_when_filtering_by_price(): void
    {
        $product = Product::create(['title' => 'Multi-variant', 'slug' => 'multi-variant', 'status' => 'published']);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'MV-DEFAULT', 'type' => 'color', 'is_default' => true,  'base_price' => 50000]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'MV-OTHER',   'type' => 'color', 'is_default' => false, 'base_price' => 999000]);

        $this->getJson('/api/v1/catalog/products?min_price=100000')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** @test */
    public function it_searches_products_by_title(): void
    {
        Product::create(['title' => 'Wireless Headphones', 'slug' => 'wireless-headphones', 'status' => 'published']);
        Product::create(['title' => 'Running Shoes',       'slug' => 'running-shoes',       'status' => 'published']);

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
        $tech = Category::create(['name' => 'Tech',    'slug' => 'tech',    'is_active' => true]);
        $fashion = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);

        $keyboard = Product::create(['title' => 'Keyboard',       'slug' => 'keyboard',       'status' => 'published', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $keyboard->id, 'sku' => 'KB-01',    'type' => 'color', 'is_default' => true, 'base_price' => 80000]);

        $monitor = Product::create(['title' => 'Monitor',         'slug' => 'monitor',         'status' => 'published', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $monitor->id,  'sku' => 'MON-01',   'type' => 'color', 'is_default' => true, 'base_price' => 300000]);

        $shirt = Product::create(['title' => 'Keyboard Shirt',    'slug' => 'keyboard-shirt',  'status' => 'published', 'category_id' => $fashion->id]);
        ProductVariant::create(['product_id' => $shirt->id,    'sku' => 'SHIRT-01', 'type' => 'color', 'is_default' => true, 'base_price' => 80000]);

        $this->getJson("/api/v1/catalog/products?category_id={$tech->id}&max_price=100000&search=Key")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Keyboard');
    }

    // ── GET /api/v1/catalog/products/admin ───────────────────────────────────

    /** @test */
    public function it_lists_products_in_every_status_on_the_admin_index(): void
    {
        Product::create(['title' => 'Published A', 'slug' => 'pub-a',   'status' => 'published']);
        Product::create(['title' => 'Draft B',     'slug' => 'draft-b', 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/products/admin')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);
    }

    /** @test */
    public function it_filters_admin_products_by_status(): void
    {
        Product::create(['title' => 'Published A', 'slug' => 'pub-a',   'status' => 'published']);
        Product::create(['title' => 'Draft B',     'slug' => 'draft-b', 'status' => 'draft']);
        Product::create(['title' => 'Draft C',     'slug' => 'draft-c', 'status' => 'draft']);

        $this->getJson('/api/v1/catalog/products/admin?status=draft')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'draft')
            ->assertJsonPath('data.1.status', 'draft');

        $this->getJson('/api/v1/catalog/products/admin?status=published')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published A');
    }

    /** @test */
    public function it_rejects_an_invalid_status_filter_on_the_admin_index(): void
    {
        $this->getJson('/api/v1/catalog/products/admin?status=archived')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    /** @test */
    public function it_filters_admin_products_by_category(): void
    {
        $tech = Category::create(['name' => 'Tech',    'slug' => 'tech',    'is_active' => true]);
        $fashion = Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'is_active' => true]);

        Product::create(['title' => 'Phone', 'slug' => 'phone', 'status' => 'draft',     'category_id' => $tech->id]);
        Product::create(['title' => 'Shirt', 'slug' => 'shirt', 'status' => 'published', 'category_id' => $fashion->id]);

        $this->getJson("/api/v1/catalog/products/admin?category_id={$tech->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category_id', $tech->id);
    }

    /** @test */
    public function it_searches_admin_products_by_title(): void
    {
        Product::create(['title' => 'Wireless Headphones', 'slug' => 'wireless-headphones', 'status' => 'draft']);
        Product::create(['title' => 'Running Shoes',       'slug' => 'running-shoes',       'status' => 'published']);

        $this->getJson('/api/v1/catalog/products/admin?search=Wireless')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Wireless Headphones');
    }

    /** @test */
    public function it_searches_admin_products_by_variant_sku(): void
    {
        $product = Product::create(['title' => 'Mystery Box', 'slug' => 'mystery-box', 'status' => 'draft']);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'SPECIAL-SKU-99', 'type' => 'color', 'is_default' => true, 'base_price' => 10000]);
        Product::create(['title' => 'Other', 'slug' => 'other', 'status' => 'published']);

        $this->getJson('/api/v1/catalog/products/admin?search=SPECIAL-SKU')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mystery Box');
    }

    /** @test */
    public function it_filters_admin_products_by_price_using_the_default_variant(): void
    {
        $cheap = Product::create(['title' => 'Budget Item', 'slug' => 'budget-item', 'status' => 'draft']);
        ProductVariant::create(['product_id' => $cheap->id, 'sku' => 'CHEAP-01', 'type' => 'color', 'is_default' => true, 'base_price' => 50000]);

        $expensive = Product::create(['title' => 'Premium Item', 'slug' => 'premium-item', 'status' => 'published']);
        ProductVariant::create(['product_id' => $expensive->id, 'sku' => 'PREM-01', 'type' => 'color', 'is_default' => true, 'base_price' => 500000]);

        $this->getJson('/api/v1/catalog/products/admin?min_price=100000')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Premium Item');
    }

    /** @test */
    public function it_paginates_the_admin_index_and_respects_per_page(): void
    {
        foreach (range(1, 5) as $i) {
            Product::create(['title' => "Product {$i}", 'slug' => "product-{$i}", 'status' => 'draft']);
        }

        $this->getJson('/api/v1/catalog/products/admin?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    /** @test */
    public function it_applies_combined_filters_on_the_admin_index(): void
    {
        $tech = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);

        $keyboard = Product::create(['title' => 'Keyboard', 'slug' => 'keyboard', 'status' => 'draft', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $keyboard->id, 'sku' => 'KB-01', 'type' => 'color', 'is_default' => true, 'base_price' => 80000]);

        // Same category + price + search term but published — excluded by the status filter.
        $keyboardPub = Product::create(['title' => 'Keyboard Pro', 'slug' => 'keyboard-pro', 'status' => 'published', 'category_id' => $tech->id]);
        ProductVariant::create(['product_id' => $keyboardPub->id, 'sku' => 'KB-02', 'type' => 'color', 'is_default' => true, 'base_price' => 80000]);

        $this->getJson("/api/v1/catalog/products/admin?status=draft&category_id={$tech->id}&max_price=100000&search=Key")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Keyboard');
    }
}
