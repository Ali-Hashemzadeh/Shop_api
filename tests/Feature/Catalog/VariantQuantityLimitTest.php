<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class VariantQuantityLimitTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->actingAsAdmin();
        $this->product = Product::create(['title' => 'Limited', 'slug' => 'limited', 'status' => 'published']);
    }

    public function test_model_defaults_to_null_casts_integer_and_preserves_null(): void
    {
        $variant = $this->variant('MODEL-LIMIT');
        $this->assertNull($variant->max_quantity_per_order);

        $variant->update(['max_quantity_per_order' => '5']);
        $this->assertSame(5, $variant->fresh()->max_quantity_per_order);

        $variant->update(['max_quantity_per_order' => null]);
        $this->assertNull($variant->fresh()->max_quantity_per_order);
    }

    public function test_standalone_create_accepts_limit_and_null_and_exposes_effective_maximum(): void
    {
        $limited = $this->postJson("/api/v1/catalog/products/{$this->product->uuid}/variants", [
            'type' => 'color', 'base_price' => 1000, 'max_quantity_per_order' => 5,
        ])->assertCreated()->assertJsonPath('max_quantity_per_order', 5);

        InventoryStock::create(['sku' => $limited->json('sku'), 'quantity' => 3, 'reserved_quantity' => 0]);
        $this->getJson('/api/v1/catalog/variants/sku/'.$limited->json('sku'))
            ->assertOk()
            ->assertJsonPath('max_quantity_per_order', 5)
            ->assertJsonPath('effective_max_quantity', 3);

        $this->postJson("/api/v1/catalog/products/{$this->product->uuid}/variants", [
            'type' => 'color', 'base_price' => 1000, 'max_quantity_per_order' => null,
        ])->assertCreated()->assertJsonPath('max_quantity_per_order', null);
    }

    public function test_standalone_create_rejects_zero_negative_float_and_decimal_string(): void
    {
        foreach ([0, -5, 1.5, '2.5'] as $invalid) {
            $this->postJson("/api/v1/catalog/products/{$this->product->uuid}/variants", [
                'type' => 'color', 'base_price' => 1000, 'max_quantity_per_order' => $invalid,
            ])->assertUnprocessable()->assertJsonValidationErrors('max_quantity_per_order');
        }
    }

    public function test_standalone_update_sets_changes_and_explicitly_clears_limit(): void
    {
        $variant = $this->variant('UPDATE-LIMIT');

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['max_quantity_per_order' => 5])
            ->assertOk()->assertJsonPath('max_quantity_per_order', 5);
        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['max_quantity_per_order' => 10])
            ->assertOk()->assertJsonPath('max_quantity_per_order', 10);
        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['max_quantity_per_order' => null])
            ->assertOk()->assertJsonPath('max_quantity_per_order', null);
    }

    public function test_failed_standalone_update_keeps_existing_limit(): void
    {
        $variant = $this->variant('INVALID-UPDATE', 5);

        $this->patchJson("/api/v1/catalog/variants/{$variant->id}", ['max_quantity_per_order' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('max_quantity_per_order');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'max_quantity_per_order' => 5]);
    }

    public function test_nested_product_create_persists_limit_and_defaults_omitted_limit_to_null(): void
    {
        $response = $this->postJson('/api/v1/catalog/products', [
            'title' => 'Nested limits', 'status' => 'published',
            'variants' => [
                ['type' => 'color', 'base_price' => 1000, 'is_default' => true, 'max_quantity_per_order' => 5],
                ['type' => 'color', 'base_price' => 2000, 'is_default' => false],
            ],
        ])->assertCreated()->assertJsonPath('variants.0.max_quantity_per_order', 5)
            ->assertJsonPath('variants.1.max_quantity_per_order', null);

        $this->assertDatabaseHas('products', ['uuid' => $response->json('id')]);
    }

    public function test_invalid_nested_create_rolls_back_product_and_variants(): void
    {
        $this->postJson('/api/v1/catalog/products', [
            'title' => 'Must rollback',
            'variants' => [[
                'type' => 'color', 'base_price' => 1000, 'is_default' => true, 'max_quantity_per_order' => 0,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.0.max_quantity_per_order');

        $this->assertDatabaseMissing('products', ['title' => 'Must rollback']);
    }

    public function test_product_update_upsert_creates_updates_and_clears_limits(): void
    {
        $existing = $this->variant('UPSERT-EXISTING', 5);

        $this->patchJson("/api/v1/catalog/products/{$this->product->uuid}", [
            'variants' => [
                ['id' => $existing->id, 'type' => 'color', 'base_price' => 1000, 'is_default' => true, 'max_quantity_per_order' => 10],
                ['type' => 'color', 'base_price' => 2000, 'is_default' => false, 'max_quantity_per_order' => 3],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $existing->id, 'max_quantity_per_order' => 10]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $this->product->id, 'max_quantity_per_order' => 3]);

        $this->patchJson("/api/v1/catalog/products/{$this->product->uuid}", [
            'variants' => [[
                'id' => $existing->id, 'type' => 'color', 'base_price' => 1000, 'is_default' => true, 'max_quantity_per_order' => null,
            ]],
        ])->assertOk();
        $this->assertNull($existing->fresh()->max_quantity_per_order);
    }

    public function test_invalid_product_upsert_does_not_update_product_or_variant(): void
    {
        $variant = $this->variant('ROLLBACK-UPSERT', 5);

        $this->patchJson("/api/v1/catalog/products/{$this->product->uuid}", [
            'title' => 'Changed title',
            'variants' => [[
                'id' => $variant->id, 'type' => 'color', 'base_price' => 1000, 'is_default' => true, 'max_quantity_per_order' => 0,
            ]],
        ])->assertUnprocessable();

        $this->assertSame('Limited', $this->product->fresh()->title);
        $this->assertSame(5, $variant->fresh()->max_quantity_per_order);
    }

    private function variant(string $sku, ?int $limit = null): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => $sku,
            'type' => 'color',
            'is_default' => true,
            'base_price' => 1000,
            'attributes' => [],
            'max_quantity_per_order' => $limit,
        ]);
    }
}
