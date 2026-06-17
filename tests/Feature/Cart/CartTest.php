<?php

namespace Tests\Feature\Cart;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\Domain\Models\InventoryStock;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private const SESSION = 'test-session-abc123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
    }

    // ── Guest: GET /api/v1/cart ───────────────────────────────────────────────

    /** @test */
    public function guest_can_view_an_empty_cart(): void
    {
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonStructure(['id', 'user_id', 'session_id', 'items', 'item_count', 'total_quantity', 'total_price'])
            ->assertJsonPath('items', [])
            ->assertJsonPath('item_count', 0)
            ->assertJsonPath('total_price', 0);
    }

    // ── Guest: POST /api/v1/cart/items ────────────────────────────────────────

    /** @test */
    public function guest_can_add_item_to_cart(): void
    {
        InventoryStock::create(['sku' => 'SHIRT-M', 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SHIRT-M', 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('item_count', 1)
            ->assertJsonPath('items.0.sku', 'SHIRT-M')
            ->assertJsonPath('items.0.quantity', 2);

        $this->assertDatabaseHas('cart_items', ['sku' => 'SHIRT-M', 'quantity' => 2]);
    }

    /** @test */
    public function adding_same_sku_twice_increments_quantity(): void
    {
        InventoryStock::create(['sku' => 'SHIRT-M', 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SHIRT-M', 'quantity' => 2]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SHIRT-M', 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('items.0.quantity', 5);

        $this->assertDatabaseHas('cart_items', ['sku' => 'SHIRT-M', 'quantity' => 5]);
    }

    /** @test */
    public function adding_item_fails_when_stock_is_zero(): void
    {
        InventoryStock::create(['sku' => 'SOLD-OUT', 'quantity' => 0, 'reserved_quantity' => 0]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SOLD-OUT', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJson(['message' => "Insufficient stock for SKU 'SOLD-OUT': requested 1, available 0."]);
    }

    /** @test */
    public function adding_item_fails_when_sku_has_no_inventory_record(): void
    {
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'GHOST-SKU', 'quantity' => 1])
            ->assertUnprocessable()
            ->assertJson(['message' => "No inventory record found for SKU 'GHOST-SKU'."]);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /** @test */
    public function add_item_requires_sku_and_quantity(): void
    {
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sku', 'quantity']);
    }

    /** @test */
    public function add_item_rejects_quantity_below_one(): void
    {
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SHIRT-M', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    // ── Authenticated user ────────────────────────────────────────────────────

    /** @test */
    public function authenticated_user_cart_is_keyed_by_user_id(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('session_id', null)
            ->assertJsonPath('item_count', 0);
    }

    /** @test */
    public function authenticated_user_can_add_item(): void
    {
        InventoryStock::create(['sku' => 'PANTS-L', 'quantity' => 5, 'reserved_quantity' => 0]);

        $user = $this->actingAsCustomer();

        $this->postJson('/api/v1/cart/items', ['sku' => 'PANTS-L', 'quantity' => 1])
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('items.0.sku', 'PANTS-L');
    }

    /** @test */
    public function guest_and_authenticated_carts_are_isolated(): void
    {
        InventoryStock::create(['sku' => 'HAT-ONE', 'quantity' => 10, 'reserved_quantity' => 0]);

        // Guest adds an item
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'HAT-ONE', 'quantity' => 1])
            ->assertCreated();

        // Authenticated user sees an empty cart
        $this->actingAsCustomer();

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }

    // ── PATCH /api/v1/cart/items/{itemId} ────────────────────────────────────

    /** @test */
    public function can_update_cart_item_quantity(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 10, 'reserved_quantity' => 0]);

        $addResponse = $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'BOOT-42', 'quantity' => 2])
            ->assertCreated();

        $itemId = $addResponse->json('items.0.id');

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 5])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 5);
    }

    /** @test */
    public function update_fails_when_quantity_exceeds_available_stock(): void
    {
        InventoryStock::create(['sku' => 'BOOT-42', 'quantity' => 3, 'reserved_quantity' => 0]);

        $addResponse = $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'BOOT-42', 'quantity' => 2])
            ->assertCreated();

        $itemId = $addResponse->json('items.0.id');

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 10])
            ->assertUnprocessable();
    }

    // ── DELETE /api/v1/cart/items/{itemId} ───────────────────────────────────

    /** @test */
    public function can_remove_a_cart_item(): void
    {
        InventoryStock::create(['sku' => 'COAT-XL', 'quantity' => 5, 'reserved_quantity' => 0]);

        $addResponse = $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'COAT-XL', 'quantity' => 1])
            ->assertCreated();

        $itemId = $addResponse->json('items.0.id');

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->deleteJson("/api/v1/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('item_count', 0);

        $this->assertDatabaseMissing('cart_items', ['id' => $itemId]);
    }

    /** @test */
    public function removing_nonexistent_item_returns_404(): void
    {
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->deleteJson('/api/v1/cart/items/99999')
            ->assertNotFound();
    }

    // ── DELETE /api/v1/cart ───────────────────────────────────────────────────

    /** @test */
    public function can_clear_the_entire_cart(): void
    {
        InventoryStock::create(['sku' => 'SHIRT-M', 'quantity' => 10, 'reserved_quantity' => 0]);
        InventoryStock::create(['sku' => 'PANTS-L', 'quantity' => 10, 'reserved_quantity' => 0]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'SHIRT-M', 'quantity' => 1]);
        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->postJson('/api/v1/cart/items', ['sku' => 'PANTS-L', 'quantity' => 2]);

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->deleteJson('/api/v1/cart')
            ->assertNoContent();

        $this->withHeaders(['X-Session-Id' => self::SESSION])
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('item_count', 0);
    }
}
