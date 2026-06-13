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
        Storage::fake();

        $this->product = Product::create([
            'title'  => 'Test Product',
            'slug'   => 'test-product',
            'status' => 'published',
        ]);
    }

    // ── POST /api/v1/catalog/products/{productId}/variants ───────────────────

    /** @test */
    public function it_can_create_a_variant_with_integer_prices(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'        => 'WATCH-BLK-M',
            'base_price' => 4999,
            'is_default' => true,
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'sku', 'is_default', 'base_price', 'compare_at_price', 'attributes', 'image_url'])
            ->assertJsonPath('sku', 'WATCH-BLK-M')
            ->assertJsonPath('base_price', 4999)
            ->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('product_variants', ['sku' => 'WATCH-BLK-M', 'base_price' => 4999]);
    }

    /** @test */
    public function it_accepts_a_whole_number_string_price_from_form_encoded_requests(): void
    {
        // postJson sends JSON integers directly; simulate form-encoded string via post()
        $response = $this->post(
            "/api/v1/catalog/products/{$this->product->id}/variants",
            ['sku' => 'SKU-FORM', 'base_price' => '2999'],
            ['Accept' => 'application/json']
        );

        // prepareForValidation casts "2999" → 2999 so the Action's Cents Rule guard passes
        $response->assertCreated()
            ->assertJsonPath('base_price', 2999);
    }

    /** @test */
    public function it_rejects_a_float_base_price_enforcing_the_cents_rule(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'        => 'SKU-FLOAT',
            'base_price' => 19.99,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }

    /** @test */
    public function it_rejects_a_decimal_string_base_price(): void
    {
        $this->post(
            "/api/v1/catalog/products/{$this->product->id}/variants",
            ['sku' => 'SKU-DEC', 'base_price' => '19.99'],
            ['Accept' => 'application/json']
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price']);
    }

    /** @test */
    public function it_requires_sku_and_base_price_to_create_a_variant(): void
    {
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'base_price']);
    }

    /** @test */
    public function it_rejects_a_duplicate_sku(): void
    {
        ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'EXISTING-SKU',
            'is_default' => false,
            'base_price' => 1000,
        ]);

        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'        => 'EXISTING-SKU',
            'base_price' => 2000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['sku']);
    }

    /** @test */
    public function it_stores_a_compare_at_price_alongside_the_base_price(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'              => 'SALE-ITEM',
            'base_price'       => 1999,
            'compare_at_price' => 2999,
        ]);

        $response->assertCreated()
            ->assertJsonPath('base_price', 1999)
            ->assertJsonPath('compare_at_price', 2999);
    }

    /** @test */
    public function it_can_create_a_variant_with_attributes(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'        => 'SHIRT-RED-L',
            'base_price' => 2999,
            'attributes' => ['color' => 'red', 'size' => 'L'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('attributes.color', 'red')
            ->assertJsonPath('attributes.size', 'L');
    }

    /** @test */
    public function it_can_create_a_variant_with_a_variant_image(): void
    {
        $response = $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'           => 'WITH-IMAGE',
            'base_price'    => 3999,
            'variant_image' => UploadedFile::fake()->image('variant-blue.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('image_url'));
    }

    /** @test */
    public function setting_a_new_default_variant_unsets_the_previous_one(): void
    {
        // Arrange: first variant is the default
        $firstVariant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'VARIANT-A',
            'is_default' => true,
            'base_price' => 1000,
        ]);

        // Act: create a second variant claiming is_default
        $this->postJson("/api/v1/catalog/products/{$this->product->id}/variants", [
            'sku'        => 'VARIANT-B',
            'base_price' => 2000,
            'is_default' => true,
        ])->assertCreated()
            ->assertJsonPath('is_default', true);

        // Assert: original default was unset
        $this->assertDatabaseHas('product_variants', [
            'sku'        => 'VARIANT-A',
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('product_variants', [
            'sku'        => 'VARIANT-B',
            'is_default' => true,
        ]);
    }

    // ── GET /api/v1/catalog/variants/{variantId} ─────────────────────────────

    /** @test */
    public function it_can_fetch_a_variant_by_id(): void
    {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku'        => 'GET-BY-ID',
            'is_default' => false,
            'base_price' => 5000,
        ]);

        $this->getJson("/api/v1/catalog/variants/{$variant->id}")
            ->assertOk()
            ->assertJsonPath('id', $variant->id)
            ->assertJsonPath('sku', 'GET-BY-ID')
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
            'sku'        => 'FIND-BY-SKU',
            'is_default' => false,
            'base_price' => 7500,
        ]);

        $this->getJson('/api/v1/catalog/variants/sku/FIND-BY-SKU')
            ->assertOk()
            ->assertJsonPath('sku', 'FIND-BY-SKU')
            ->assertJsonPath('base_price', 7500);
    }

    /** @test */
    public function it_returns_404_when_no_variant_matches_the_sku(): void
    {
        $this->getJson('/api/v1/catalog/variants/sku/GHOST-SKU')
            ->assertNotFound();
    }
}
