<?php

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class CartQuantityLimitTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'quantity-limit-guest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
    }

    public function test_guest_add_allows_below_and_exact_limit_but_rejects_resulting_excess(): void
    {
        $this->variant('GUEST-LIMIT', 5, 20);

        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'GUEST-LIMIT', 'quantity' => 3])
            ->assertCreated()->assertJsonPath('items.0.quantity', 3);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'GUEST-LIMIT', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('items.0.quantity', 5);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'GUEST-LIMIT', 'quantity' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('cart_items', ['sku' => 'GUEST-LIMIT', 'quantity' => 5]);
    }

    public function test_authenticated_add_rejects_single_request_above_limit_and_keeps_cart_unchanged(): void
    {
        $this->variant('AUTH-LIMIT', 5, 20);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/cart/items', ['sku' => 'AUTH-LIMIT', 'quantity' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseMissing('cart_items', ['sku' => 'AUTH-LIMIT']);
    }

    public function test_null_limit_preserves_stock_only_behavior_and_stock_can_be_lower_than_limit(): void
    {
        $this->variant('NO-LIMIT', null, 8);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'NO-LIMIT', 'quantity' => 8])
            ->assertCreated()->assertJsonPath('items.0.quantity', 8);

        $this->variant('STOCK-LOWER', 5, 3);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'STOCK-LOWER', 'quantity' => 4])
            ->assertUnprocessable();
        $this->assertDatabaseMissing('cart_items', ['sku' => 'STOCK-LOWER']);
    }

    public function test_update_allows_exact_limit_rejects_excess_and_preserves_existing_quantity(): void
    {
        $this->variant('UPDATE-LIMIT', 5, 20);
        $itemId = $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'UPDATE-LIMIT', 'quantity' => 3])
            ->assertCreated()->json('items.0.id');

        $this->guest()->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 5])
            ->assertOk()->assertJsonPath('items.0.quantity', 5);
        $this->guest()->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 6])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('cart_items', ['id' => $itemId, 'quantity' => 5]);
    }

    public function test_merge_clamps_duplicate_sku_to_per_order_limit(): void
    {
        $this->variant('MERGE-LIMIT', 5, 20);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'MERGE-LIMIT', 'quantity' => 3]);
        $this->actingAsCustomer();
        $this->postJson('/api/v1/cart/items', ['sku' => 'MERGE-LIMIT', 'quantity' => 4]);

        $this->postJson('/api/v1/cart/merge', ['session_id' => self::SESSION])
            ->assertOk()->assertJsonPath('items.0.quantity', 5);
    }

    public function test_merge_uses_lower_of_stock_and_limit_and_preserves_other_items(): void
    {
        $this->variant('LOWER-WINS', 5, 3);
        $this->variant('OTHER-ITEM', null, 10);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'LOWER-WINS', 'quantity' => 2]);
        $this->guest()->postJson('/api/v1/cart/items', ['sku' => 'OTHER-ITEM', 'quantity' => 1]);
        $this->actingAsCustomer();
        $this->postJson('/api/v1/cart/items', ['sku' => 'LOWER-WINS', 'quantity' => 2]);

        $response = $this->postJson('/api/v1/cart/merge', ['session_id' => self::SESSION])->assertOk();
        $items = collect($response->json('items'))->keyBy('sku');
        $this->assertSame(3, $items['LOWER-WINS']['quantity']);
        $this->assertSame(1, $items['OTHER-ITEM']['quantity']);
    }

    public function test_cart_resource_exposes_limit_effective_remaining_and_valid_state(): void
    {
        $variant = $this->variant('RESOURCE-LIMIT', 10, 20);
        $cart = Cart::create(['session_id' => self::SESSION]);
        CartItem::create(['cart_id' => $cart->id, 'sku' => 'RESOURCE-LIMIT', 'quantity' => 8]);
        $variant->update(['max_quantity_per_order' => 5]);

        $this->guest()->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('items.0.available_stock', 20)
            ->assertJsonPath('items.0.max_quantity_per_order', 5)
            ->assertJsonPath('items.0.effective_max_quantity', 5)
            ->assertJsonPath('items.0.remaining_addable_quantity', 0)
            ->assertJsonPath('items.0.quantity_valid', false);
    }

    private function guest(): self
    {
        return $this->withHeaders(['X-Session-Id' => self::SESSION]);
    }

    private function variant(string $sku, ?int $limit, int $stock): ProductVariant
    {
        $product = Product::create(['title' => $sku, 'slug' => strtolower($sku), 'status' => 'published']);
        $variant = ProductVariant::create([
            'product_id' => $product->id, 'sku' => $sku, 'type' => 'color',
            'is_default' => true, 'base_price' => 1000, 'attributes' => [],
            'max_quantity_per_order' => $limit,
        ]);
        InventoryStock::create(['sku' => $sku, 'quantity' => $stock, 'reserved_quantity' => 0]);

        return $variant;
    }
}
