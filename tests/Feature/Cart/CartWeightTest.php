<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class CartWeightTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'weight-session-xyz';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
    }

    private function createVariant(string $sku, ?int $weightGrams, int $qty = 20): void
    {
        $product = Product::create([
            'title' => "Product {$sku}",
            'slug' => strtolower($sku),
            'status' => 'published',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => 10000,
            'weight_grams' => $weightGrams,
            'is_default' => true,
            'attributes' => [],
        ]);
        InventoryStock::create(['sku' => $sku, 'quantity' => $qty, 'reserved_quantity' => 0]);
    }

    /** @test */
    public function cart_total_weight_sums_weight_times_quantity_across_lines(): void
    {
        $this->createVariant('W-A', 218);
        $this->createVariant('W-B', 500);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'W-A', 'quantity' => 3]);
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'W-B', 'quantity' => 2]);

        // 218*3 + 500*2 = 654 + 1000 = 1654 grams.
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('total_weight_grams', 1654);
    }

    /** @test */
    public function a_variant_without_a_weight_contributes_zero(): void
    {
        $this->createVariant('W-KNOWN', 300);
        $this->createVariant('W-NULL', null);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'W-KNOWN', 'quantity' => 2]);
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'W-NULL', 'quantity' => 5]);

        // Only the weighted line counts: 300*2 = 600.
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('total_weight_grams', 600);
    }
}
