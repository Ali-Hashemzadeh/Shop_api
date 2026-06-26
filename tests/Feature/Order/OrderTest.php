<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
        $this->seedOrderPermissions();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createAddress(int $userId): int
    {
        return DB::table('addresses')->insertGetId([
            'user_id'             => $userId,
            'title'               => 'Home',
            'province_id'         => null,
            'city_id'             => null,
            'postal_code'         => '1234512345',
            'address'             => '123 Test Street',
            'is_default_shipping' => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    private function createVariantWithStock(string $sku, int $basePrice, int $qty): void
    {
        $product = Product::create([
            'title'  => "Product for {$sku}",
            'slug'   => $sku,
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku'        => $sku,
            'type'       => 'color',
            'base_price' => $basePrice,
            'is_default' => true,
            'attributes' => [],
        ]);

        InventoryStock::create(['sku' => $sku, 'quantity' => $qty, 'reserved_quantity' => 0]);
    }

    private function createCartWithItem(int $userId, string $sku, int $qty): Cart
    {
        $cart = Cart::create(['user_id' => $userId]);
        CartItem::create(['cart_id' => $cart->id, 'sku' => $sku, 'quantity' => $qty]);

        return $cart;
    }

    // ── Scenario 1: Happy path — snapshot prices, reserve stock, clear cart ──

    /** @test */
    public function it_creates_an_order_with_snapshotted_prices_and_reserves_stock(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('TEST-001', 50000, 10);
        $this->createCartWithItem($user->id, 'TEST-001', 2);

        $this->postJson('/api/v1/orders', [
            'address_id'         => $addressId,
            'shipment_method_id' => 1,
        ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('total_amount', 100000)
            ->assertJsonPath('items.0.sku', 'TEST-001')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.price_per_unit', 50000)
            ->assertJsonPath('items.0.line_total', 100000);

        $this->assertDatabaseEmpty('cart_items');
        $this->assertDatabaseHas('inventory_stocks', [
            'sku'               => 'TEST-001',
            'reserved_quantity' => 2,
        ]);
    }

    // ── Scenario 2: Auto-cancel — existing pending order is replaced ──────────

    /** @test */
    public function it_cancels_existing_pending_order_and_releases_its_reservations(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);

        InventoryStock::create(['sku' => 'A-001', 'quantity' => 5, 'reserved_quantity' => 1]);
        $oldOrder = Order::create([
            'user_id'          => $user->id,
            'status'           => 'pending',
            'total_amount'     => 50000,
            'shipping_cost'    => 0,
            'tax_amount'       => 0,
            'shipping_address' => ['address' => 'old address'],
        ]);
        OrderItem::create([
            'order_id'       => $oldOrder->id,
            'sku'            => 'A-001',
            'product_title'  => 'Old Product',
            'quantity'       => 1,
            'price_per_unit' => 50000,
            'line_total'     => 50000,
        ]);

        $this->createVariantWithStock('B-001', 30000, 5);
        $this->createCartWithItem($user->id, 'B-001', 1);

        $this->postJson('/api/v1/orders', [
            'address_id'         => $addressId,
            'shipment_method_id' => 1,
        ])->assertStatus(201);

        $this->assertDatabaseHas('orders', ['id' => $oldOrder->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'A-001', 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'B-001', 'reserved_quantity' => 1]);
        $this->assertDatabaseCount('orders', 2);
    }

    // ── Scenario 3: TTL expiry — command cancels stale pending orders ─────────

    /** @test */
    public function it_cancels_expired_pending_orders_and_releases_reservations_via_command(): void
    {
        $user = $this->actingAsCustomer();
        InventoryStock::create(['sku' => 'EXP-001', 'quantity' => 5, 'reserved_quantity' => 1]);

        $expiredOrder = Order::create([
            'user_id'          => $user->id,
            'status'           => 'pending',
            'total_amount'     => 50000,
            'shipping_cost'    => 0,
            'tax_amount'       => 0,
            'shipping_address' => ['address' => 'test'],
        ]);
        OrderItem::create([
            'order_id'       => $expiredOrder->id,
            'sku'            => 'EXP-001',
            'product_title'  => 'Expiring Product',
            'quantity'       => 1,
            'price_per_unit' => 50000,
            'line_total'     => 50000,
        ]);

        DB::table('orders')
            ->where('id', $expiredOrder->id)
            ->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('orders:cancel-expired')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $expiredOrder->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'EXP-001', 'reserved_quantity' => 0]);
    }

    // ── Auth matrix ───────────────────────────────────────────────────────────

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/v1/orders', [])->assertStatus(401);
    }

    /** @test */
    public function validation_fails_without_required_fields(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/orders', [])->assertStatus(422);
    }

    /** @test */
    public function creating_order_from_empty_cart_returns_422(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);

        $this->postJson('/api/v1/orders', [
            'address_id'         => $addressId,
            'shipment_method_id' => 1,
        ])->assertStatus(422);
    }
}
