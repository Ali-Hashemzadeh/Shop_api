<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Tests\TestCase;

/**
 * Verifies the authorization boundary between public and admin routes.
 * No actingAsAdmin() in setUp — each test controls auth state explicitly.
 */
class CatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
    }

    // ── Unauthenticated → 401 on all admin (write) routes ───────────────────

    /** @test */
    public function unauthenticated_request_cannot_create_a_category(): void
    {
        $this->postJson('/api/v1/catalog/categories', ['name' => 'Test'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_update_a_category(): void
    {
        $this->patchJson('/api/v1/catalog/categories/1', ['name' => 'Test'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_delete_a_category(): void
    {
        $this->deleteJson('/api/v1/catalog/categories/1')
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_create_a_product(): void
    {
        $this->postJson('/api/v1/catalog/products', ['title' => 'Test'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_update_a_product(): void
    {
        $this->patchJson('/api/v1/catalog/products/1', ['title' => 'Test'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_delete_a_product(): void
    {
        $this->deleteJson('/api/v1/catalog/products/1')
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_access_the_admin_product_endpoint(): void
    {
        $this->getJson('/api/v1/catalog/products/1/admin')
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_create_a_variant(): void
    {
        $this->postJson('/api/v1/catalog/products/1/variants', ['sku' => 'X'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_update_a_variant(): void
    {
        $this->patchJson('/api/v1/catalog/variants/1', ['sku' => 'X'])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_delete_a_variant(): void
    {
        $this->deleteJson('/api/v1/catalog/variants/1')
            ->assertUnauthorized();
    }

    // ── Customer (non-admin) → 403 on all admin routes ──────────────────────

    /** @test */
    public function customer_cannot_create_a_category(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/catalog/categories', ['name' => 'Test'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_update_a_category(): void
    {
        $this->actingAsCustomer();

        $this->patchJson('/api/v1/catalog/categories/1', ['name' => 'Test'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_delete_a_category(): void
    {
        $this->actingAsCustomer();

        $this->deleteJson('/api/v1/catalog/categories/1')
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_create_a_product(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/catalog/products', ['title' => 'Test'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_update_a_product(): void
    {
        $this->actingAsCustomer();

        $this->patchJson('/api/v1/catalog/products/1', ['title' => 'Test'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_delete_a_product(): void
    {
        $this->actingAsCustomer();

        $this->deleteJson('/api/v1/catalog/products/1')
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_access_the_admin_product_endpoint(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/catalog/products/1/admin')
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_create_a_variant(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/catalog/products/1/variants', ['sku' => 'X'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_update_a_variant(): void
    {
        $this->actingAsCustomer();

        $this->patchJson('/api/v1/catalog/variants/1', ['sku' => 'X'])
            ->assertForbidden();
    }

    /** @test */
    public function customer_cannot_delete_a_variant(): void
    {
        $this->actingAsCustomer();

        $this->deleteJson('/api/v1/catalog/variants/1')
            ->assertForbidden();
    }

    // ── Public routes → accessible without auth (200 or 404, never 401/403) ──

    /** @test */
    public function public_category_show_route_does_not_require_authentication(): void
    {
        $category = Category::create(['name' => 'Books', 'slug' => 'books', 'is_active' => true]);

        $this->getJson("/api/v1/catalog/categories/{$category->id}")
            ->assertOk();
    }

    /** @test */
    public function public_category_roots_route_does_not_require_authentication(): void
    {
        $this->getJson('/api/v1/catalog/categories/roots')
            ->assertOk();
    }

    /** @test */
    public function public_product_show_route_does_not_require_authentication(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);

        $this->getJson("/api/v1/catalog/products/{$product->id}")
            ->assertOk();
    }

    /** @test */
    public function public_product_by_slug_route_does_not_require_authentication(): void
    {
        Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);

        $this->getJson('/api/v1/catalog/products/slug/widget')
            ->assertOk();
    }

    /** @test */
    public function public_products_by_category_route_does_not_require_authentication(): void
    {
        $category = Category::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);

        $this->getJson("/api/v1/catalog/categories/{$category->id}/products")
            ->assertOk();
    }

    /** @test */
    public function public_variant_show_route_does_not_require_authentication(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'WGT-001',
            'is_default' => true,
            'base_price' => 1000,
        ]);

        $this->getJson("/api/v1/catalog/variants/{$variant->id}")
            ->assertOk();
    }

    /** @test */
    public function public_variant_by_sku_route_does_not_require_authentication(): void
    {
        $product = Product::create(['title' => 'Widget', 'slug' => 'widget', 'status' => 'published']);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => 'WGT-SKU',
            'is_default' => true,
            'base_price' => 1000,
        ]);

        $this->getJson('/api/v1/catalog/variants/sku/WGT-SKU')
            ->assertOk();
    }
}
