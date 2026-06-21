<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->actingAsAdmin();

        $this->product = Product::create([
            'title' => 'Test Product',
            'slug' => 'test-product',
            'status' => 'published',
        ]);
    }

    // ── POST /api/v1/catalog/products/{productId}/variants ───────────────────

    /** @test */
    public function it_can_create_a_variant_with_integer_prices(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'WATCH-BLK-M',
            'type' => 'color',
            'base_price' => 4999,
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'sku', 'type', 'is_default', 'base_price', 'compare_at_price', 'attributes', 'image_url'])
            ->assertJsonPath('sku', 'WATCH-BLK-M')
            ->assertJsonPath('type', 'color')
            ->assertJsonPath('base_price', 4999)
            ->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('product_variants', ['sku' => 'WATCH-BLK-M', 'type' => 'color', 'base_price' => 4999]);
    }

    /** @test */
    public function it_accepts_a_whole_number_string_price_from_form_encoded_requests(): void
    {
        $response = $this->post(
            "/api/v1/catalog/products/{$this->product->id}/variants",
            ['sku' => 'SKU-FORM', 'type' => 'color', 'base_price' => '2999'],
            ['Accept' => 'application/json']
        );

        $response->assertCreated()
            ->assertJsonPath('base_price', 2999);
    }

    /** @test */
    public function it_rejects_a_float_base_price_enforcing_the_cents_rule(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'SKU-FLOAT',
            'type' => 'color',
            'base_price' => 19.99,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }

    /** @test */
    public function it_rejects_a_decimal_string_base_price(): void
    {
        $this->post(
            "/api/v1/catalog/products/{$this->product->id}/variants",
            ['sku' => 'SKU-DEC', 'type' => 'color', 'base_price' => '19.99'],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }

    /** @test */
    public function it_requires_sku_base_price_and_type_to_create_a_variant(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'base_price', 'type']);
    }

    /** @test */
    public function it_rejects_an_invalid_variant_type(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'SKU-BAD-TYPE',
            'type' => 'size',
            'base_price' => 1000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    /** @test */
    public function it_can_create_a_variant_with_type_image(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'SKU-IMG',
            'type' => 'image',
            'base_price' => 5000,
        ])->assertCreated()
            ->assertJsonPath('type', 'image');

        $this->assertDatabaseHas('product_variants', ['sku' => 'SKU-IMG', 'type' => 'image']);
    }

    /** @test */
    public function it_rejects_a_duplicate_sku(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'EXISTING-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'EXISTING-SKU',
            'type' => 'color',
            'base_price' => 2000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    /** @test */
    public function it_stores_a_compare_at_price_alongside_the_base_price(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'SALE-ITEM',
            'type' => 'color',
            'base_price' => 1999,
            'compare_at_price' => 2999,
        ])->assertCreated()
            ->assertJsonPath('base_price', 1999)
            ->assertJsonPath('compare_at_price', 2999);
    }

    /** @test */
    public function it_can_create_a_variant_with_attributes(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'SHIRT-RED-L',
            'type' => 'color',
            'base_price' => 2999,
            'attributes' => ['color' => 'red', 'size' => 'L'],
        ])->assertCreated()
            ->assertJsonPath('attributes.color', 'red')
            ->assertJsonPath('attributes.size', 'L');
    }

    /** @test */
    public function it_can_create_a_variant_with_a_variant_image(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'WITH-IMAGE',
            'type' => 'image',
            'base_price' => 3999,
            'variant_image' => UploadedFile::fake()->image('variant-blue.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('image_url'));
    }

    /** @test */
    public function setting_a_new_default_variant_unsets_the_previous_one(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'VARIANT-A',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
        ]);

        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku' => 'VARIANT-B',
            'type' => 'color',
            'base_price' => 2000,
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('product_variants', ['sku' => 'VARIANT-A', 'is_default' => false]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'VARIANT-B', 'is_default' => true]);
    }

    // ── PATCH /api/v1/catalog/variants/{variantId} ───────────────────────────

    /** @test */
    public function it_can_update_a_variant_sku(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'OLD-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['sku' => 'NEW-SKU'])
            ->assertOk()
            ->assertJsonPath('sku', 'NEW-SKU');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'sku' => 'NEW-SKU']);
    }

    /** @test */
    public function it_can_update_a_variant_type(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TYPE-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['type' => 'image'])
            ->assertOk()
            ->assertJsonPath('type', 'image');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'type' => 'image']);
    }

    /** @test */
    public function it_rejects_an_invalid_type_on_update(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TYPE-UPDATE',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['type' => 'size'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    }

    /** @test */
    public function it_can_update_variant_prices(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'PRICE-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", [
            'base_price' => 2500,
            'compare_at_price' => 3000,
        ])->assertOk()
            ->assertJsonPath('base_price', 2500)
            ->assertJsonPath('compare_at_price', 3000);
    }

    /** @test */
    public function it_rejects_a_float_price_on_variant_update(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FLOAT-UPDATE',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['base_price' => 19.99])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }

    /** @test */
    public function it_rejects_updating_to_a_duplicate_sku(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'TAKEN-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 500,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'MY-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 600,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['sku' => 'TAKEN-SKU'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    /** @test */
    public function it_allows_patching_without_changing_the_sku(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'KEEP-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", [
            'sku' => 'KEEP-SKU',
            'base_price' => 2000,
        ])->assertOk()
            ->assertJsonPath('base_price', 2000);
    }

    /** @test */
    public function updating_a_variant_to_default_unsets_the_previous_default(): void
    {
        $variantA = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'VAR-A',
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
        ]);
        $variantB = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'VAR-B',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 2000,
        ]);

        $this->patchJson("/api/v1/catalog/variants/{$variantB->id}", ['is_default' => true])
            ->assertOk()
            ->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('product_variants', ['sku' => 'VAR-A', 'is_default' => false]);
        $this->assertDatabaseHas('product_variants', ['sku' => 'VAR-B', 'is_default' => true]);
    }

    /** @test */
    public function it_returns_404_when_updating_a_non_existent_variant(): void
    {
        $this->patchJson('/api/v1/catalog/variants/99999', ['sku' => 'GHOST'])
            ->assertNotFound();
    }

    // ── DELETE /api/v1/catalog/variants/{variantId} ──────────────────────────

    /** @test */
    public function it_can_delete_a_variant(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'DELETE-ME',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 500,
        ]);

        $this->deleteJson("/api/v1/catalog/variants/{$variant->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('product_variants', ['id' => $variant->id]);
    }

    /** @test */
    public function it_returns_404_when_deleting_a_non_existent_variant(): void
    {
        $this->deleteJson('/api/v1/catalog/variants/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/variants/{variantId} ─────────────────────────────

    /** @test */
    public function it_can_fetch_a_variant_by_id(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'GET-BY-ID',
            'type' => 'image',
            'is_default' => false,
            'base_price' => 5000,
        ]);

        $this->getJson("/api/v1/catalog/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('id', $variant->id)
            ->assertJsonPath('sku', 'GET-BY-ID')
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('base_price', 5000);
    }

    /** @test */
    public function it_returns_404_when_variant_is_not_found(): void
    {
        $this->getJson('/api/v1/catalog/variants/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/variants/sku/{sku} ───────────────────────────────

    /** @test */
    public function it_can_fetch_a_variant_by_sku(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => 'FIND-BY-SKU',
            'type' => 'color',
            'is_default' => false,
            'base_price' => 7500,
        ]);

        $this->getJson('/api/v1/catalog/variants/sku/FIND-BY-SKU')
            ->assertOk()
            ->assertJsonPath('sku', 'FIND-BY-SKU')
            ->assertJsonPath('type', 'color')
            ->assertJsonPath('base_price', 7500);
    }

    /** @test */
    public function it_returns_404_when_no_variant_matches_the_sku(): void
    {
        $this->getJson('/api/v1/catalog/variants/sku/GHOST-SKU')
            ->assertNotFound();
    }
}
