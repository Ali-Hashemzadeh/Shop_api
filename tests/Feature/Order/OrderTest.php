<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Media\Domain\Models\Media;
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
            'user_id' => $userId,
            'title' => 'Home',
            'province_id' => null,
            'city_id' => null,
            'postal_code' => '1234512345',
            'address' => '123 Test Street',
            'is_default_shipping' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVariantWithStock(string $sku, int $basePrice, int $qty): void
    {
        $product = Product::create([
            'title' => "Product for {$sku}",
            'slug' => $sku,
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
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

    // ── Scenario 1: Happy path — snapshot prices and reserve stock ──

    /** @test */
    public function it_creates_an_order_with_snapshotted_prices_and_reserves_stock(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('TEST-001', 50000, 10);
        $this->createCartWithItem($user->id, 'TEST-001', 2);

        $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
        ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('total_amount', 100000)
            ->assertJsonPath('items.0.sku', 'TEST-001')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonPath('items.0.price_per_unit', 50000)
            ->assertJsonPath('items.0.line_total', 100000);

        $this->assertDatabaseHas('cart_items', [
            'sku' => 'TEST-001',
            'quantity' => 2,
        ]);
        $this->assertDatabaseHas('inventory_stocks', [
            'sku' => 'TEST-001',
            'reserved_quantity' => 2,
        ]);
    }

    // ── Scenario 1b: Immutable snapshots — customer + product ─────────────────

    /** @test */
    public function it_stores_an_immutable_customer_snapshot_on_order_creation(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('SNAP-001', 40000, 5);
        $this->createCartWithItem($user->id, 'SNAP-001', 1);

        $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
        ])
            ->assertStatus(201)
            ->assertJsonPath('customer_snapshot.name', $user->name)
            ->assertJsonPath('customer_snapshot.last_name', $user->last_name)
            ->assertJsonPath('customer_snapshot.phone', $user->phone)
            ->assertJsonPath('customer_snapshot.email', $user->email);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $this->assertSame([
            'name' => $user->name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
        ], $order->customer_snapshot);
    }

    /** @test */
    public function it_stores_an_immutable_product_snapshot_on_order_item_creation(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);

        $media = Media::create([
            'file_path' => 'products/ip16.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'original_name' => 'ip16.jpg',
        ]);

        $product = Product::create([
            'title' => 'iPhone 16 Pro',
            'slug' => 'iphone-16-pro',
            'status' => 'published',
        ]);

        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'IPH16-BLK-256',
            'type' => 'color',
            'base_price' => 500000,
            'is_default' => true,
            'media_id' => $media->id,
            'attributes' => ['color' => 'Black', 'storage' => '256GB'],
        ]);

        InventoryStock::create(['sku' => 'IPH16-BLK-256', 'quantity' => 5, 'reserved_quantity' => 0]);
        $this->createCartWithItem($user->id, 'IPH16-BLK-256', 1);

        $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
        ])
            ->assertStatus(201)
            ->assertJsonPath('items.0.product_snapshot.title', 'iPhone 16 Pro')
            ->assertJsonPath('items.0.product_snapshot.sku', 'IPH16-BLK-256')
            ->assertJsonPath('items.0.product_snapshot.attributes.color', 'Black')
            ->assertJsonPath('items.0.product_snapshot.attributes.storage', '256GB');

        $orderItem = OrderItem::where('sku', 'IPH16-BLK-256')->latest('id')->first();
        $this->assertSame('iPhone 16 Pro', $orderItem->product_snapshot['title']);
        $this->assertSame('IPH16-BLK-256', $orderItem->product_snapshot['sku']);
        $this->assertNotNull($orderItem->product_snapshot['image_url']);
        $this->assertSame(['color' => 'Black', 'storage' => '256GB'], $orderItem->product_snapshot['attributes']);
    }

    /** @test */
    public function order_snapshots_remain_unchanged_after_later_profile_and_catalog_updates(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);

        $product = Product::create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'status' => 'published',
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'IMMUT-001',
            'type' => 'color',
            'base_price' => 20000,
            'is_default' => true,
            'attributes' => ['color' => 'Red'],
        ]);
        InventoryStock::create(['sku' => 'IMMUT-001', 'quantity' => 5, 'reserved_quantity' => 0]);
        $this->createCartWithItem($user->id, 'IMMUT-001', 1);

        $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(201);

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $orderItem = $order->items()->first();

        $originalCustomerSnapshot = $order->customer_snapshot;
        $originalProductSnapshot = $orderItem->product_snapshot;

        // Simulate later profile + catalog edits — the snapshot must not move.
        $user->update([
            'name' => 'Changed Name',
            'last_name' => 'Changed Last',
            'phone' => '09999999999',
            'email' => 'changed@example.com',
        ]);
        $product->update(['title' => 'Renamed Product']);

        $order->refresh();
        $orderItem->refresh();

        $this->assertSame($originalCustomerSnapshot, $order->customer_snapshot);
        $this->assertSame($originalProductSnapshot, $orderItem->product_snapshot);
        $this->assertNotSame('Changed Name', $order->customer_snapshot['name']);
        $this->assertNotSame('Renamed Product', $orderItem->product_snapshot['title']);
    }

    // ── Scenario 2: Auto-cancel — existing pending order is replaced ──────────

    /** @test */
    public function it_cancels_existing_pending_order_and_releases_its_reservations(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);

        InventoryStock::create(['sku' => 'A-001', 'quantity' => 5, 'reserved_quantity' => 1]);
        $oldOrder = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 50000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'old address'],
        ]);
        OrderItem::create([
            'order_id' => $oldOrder->id,
            'sku' => 'A-001',
            'product_title' => 'Old Product',
            'quantity' => 1,
            'price_per_unit' => 50000,
            'line_total' => 50000,
        ]);

        $this->createVariantWithStock('B-001', 30000, 5);
        $this->createCartWithItem($user->id, 'B-001', 1);

        $this->postJson('/api/v1/orders', [
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
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
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 50000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'test'],
        ]);
        OrderItem::create([
            'order_id' => $expiredOrder->id,
            'sku' => 'EXP-001',
            'product_title' => 'Expiring Product',
            'quantity' => 1,
            'price_per_unit' => 50000,
            'line_total' => 50000,
        ]);

        DB::table('orders')
            ->where('id', $expiredOrder->id)
            ->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('orders:cancel-expired')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $expiredOrder->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'EXP-001', 'reserved_quantity' => 0]);
    }

    // ── Scenario 4: User cancels own pending order — releases reservations ────

    /** @test */
    public function it_cancels_own_pending_order_and_releases_reservations(): void
    {
        $user = $this->actingAsCustomer();
        InventoryStock::create(['sku' => 'CAN-001', 'quantity' => 5, 'reserved_quantity' => 2]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 60000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'test'],
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'CAN-001',
            'product_title' => 'Cancelable',
            'quantity' => 2,
            'price_per_unit' => 30000,
            'line_total' => 60000,
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('status', 'cancelled');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'CAN-001', 'reserved_quantity' => 0]);
    }

    // ── Scenario 5: Ownership — cannot cancel someone else's order ────────────

    /** @test */
    public function it_forbids_cancelling_another_users_order(): void
    {
        $owner = User::factory()->create();
        $order = Order::create([
            'user_id' => $owner->id,
            'status' => 'pending',
            'total_amount' => 60000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'test'],
        ]);

        $this->actingAsCustomer(); // a different authenticated user

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    /** @test */
    public function cancelling_a_nonexistent_order_returns_404(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/orders/99999/cancel')->assertStatus(404);
    }

    /** @test */
    public function it_rejects_cancelling_a_non_pending_order(): void
    {
        $user = $this->actingAsCustomer();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'paid',
            'total_amount' => 60000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'test'],
        ]);

        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    // ── Auth matrix ───────────────────────────────────────────────────────────

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/v1/orders', [])->assertStatus(401);
    }

    /** @test */
    public function unauthenticated_cancel_returns_401(): void
    {
        $this->postJson('/api/v1/orders/1/cancel')->assertStatus(401);
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
            'address_id' => $addressId,
            'shipment_method_code' => 'in_person_pickup',
        ])->assertStatus(422);
    }
}
