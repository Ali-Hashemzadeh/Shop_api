<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Brand;
use Modules\Catalog\Domain\Models\Product;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
    }

    // ── Public reads ──────────────────────────────────────────────────────────

    /** @test */
    public function anyone_can_list_active_brands(): void
    {
        Brand::create(['name' => 'Samsung', 'slug' => 'samsung', 'is_active' => true]);
        Brand::create(['name' => 'Apple', 'slug' => 'apple', 'is_active' => true]);

        $this->getJson('/api/v1/catalog/brands')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'is_active', 'image_url']], 'links', 'meta'])
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function brand_list_can_be_searched_by_name(): void
    {
        Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        Brand::create(['name' => 'Apple', 'slug' => 'apple']);

        $this->getJson('/api/v1/catalog/brands?search=sung')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Samsung');
    }

    /** @test */
    public function anyone_can_view_a_single_brand(): void
    {
        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);

        $this->getJson("/api/v1/catalog/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Samsung')
            ->assertJsonPath('slug', 'samsung');
    }

    /** @test */
    public function showing_a_missing_brand_returns_404(): void
    {
        $this->getJson('/api/v1/catalog/brands/9999')->assertStatus(404);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    /** @test */
    public function an_admin_can_create_a_brand_and_slug_is_auto_generated(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/catalog/brands', ['name' => 'Xiaomi Global'])
            ->assertCreated()
            ->assertJsonPath('name', 'Xiaomi Global')
            ->assertJsonPath('slug', 'xiaomi-global')
            ->assertJsonPath('is_active', true);

        $this->assertDatabaseHas('brands', ['name' => 'Xiaomi Global', 'slug' => 'xiaomi-global']);
    }

    /** @test */
    public function creating_a_brand_requires_a_name(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/catalog/brands', [])->assertStatus(422);
    }

    /** @test */
    public function creating_a_brand_rejects_a_duplicate_slug(): void
    {
        Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $this->actingAsAdmin();

        $this->postJson('/api/v1/catalog/brands', ['name' => 'Another', 'slug' => 'samsung'])
            ->assertStatus(422);
    }

    /** @test */
    public function a_customer_cannot_create_a_brand(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/catalog/brands', ['name' => 'Samsung'])->assertStatus(403);
    }

    /** @test */
    public function a_guest_cannot_create_a_brand(): void
    {
        $this->postJson('/api/v1/catalog/brands', ['name' => 'Samsung'])->assertStatus(401);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    /** @test */
    public function an_admin_can_update_a_brand(): void
    {
        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/catalog/brands/{$brand->id}", ['name' => 'Samsung Electronics'])
            ->assertOk()
            ->assertJsonPath('name', 'Samsung Electronics');

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Samsung Electronics']);
    }

    /** @test */
    public function a_customer_cannot_update_a_brand(): void
    {
        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $this->actingAsCustomer();

        $this->patchJson("/api/v1/catalog/brands/{$brand->id}", ['name' => 'Nope'])->assertStatus(403);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /** @test */
    public function an_admin_can_delete_a_brand_and_referring_products_are_unlinked(): void
    {
        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $product = Product::create([
            'brand_id' => $brand->id,
            'title' => 'Galaxy',
            'slug' => 'galaxy',
            'status' => 'published',
        ]);

        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/catalog/brands/{$brand->id}")->assertNoContent();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'brand_id' => null]);
    }

    /** @test */
    public function a_customer_cannot_delete_a_brand(): void
    {
        $brand = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $this->actingAsCustomer();

        $this->deleteJson("/api/v1/catalog/brands/{$brand->id}")->assertStatus(403);
    }

    // ── Product ↔ brand wiring ─────────────────────────────────────────────────

    /** @test */
    public function products_can_be_filtered_by_brand(): void
    {
        $samsung = Brand::create(['name' => 'Samsung', 'slug' => 'samsung']);
        $apple = Brand::create(['name' => 'Apple', 'slug' => 'apple']);

        Product::create(['brand_id' => $samsung->id, 'title' => 'Galaxy', 'slug' => 'galaxy', 'status' => 'published']);
        Product::create(['brand_id' => $apple->id, 'title' => 'iPhone', 'slug' => 'iphone', 'status' => 'published']);

        $this->getJson("/api/v1/catalog/products?brand_id={$samsung->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Galaxy')
            ->assertJsonPath('data.0.brand_id', $samsung->id);
    }
}
