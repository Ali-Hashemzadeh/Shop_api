<?php

namespace Tests\Feature\Order;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Tests\TestCase;

class AdminOrderTest extends TestCase
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

    private function createPendingOrder(User $user, string $sku = 'ADM-001', int $qty = 2, int $price = 50000, int $reserved = 2): Order
    {
        InventoryStock::firstOrCreate(
            ['sku' => $sku],
            ['quantity' => 10, 'reserved_quantity' => $reserved],
        );

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => $price * $qty,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => '123 Test Street'],
            'customer_snapshot' => [
                'name' => $user->name,
                'last_name' => $user->last_name,
                'phone' => $user->phone,
                'email' => $user->email,
            ],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku' => $sku,
            'product_title' => 'Admin Product',
            'variant_attributes' => ['color' => 'Black'],
            'product_snapshot' => [
                'title' => 'Admin Product',
                'sku' => $sku,
                'image_url' => '/storage/products/admin.jpg',
                'attributes' => ['color' => 'Black'],
            ],
            'quantity' => $qty,
            'price_per_unit' => $price,
            'line_total' => $price * $qty,
        ]);

        return $order;
    }

    // ── 1. Admin can list orders ───────────────────────────────────────────────

    /** @test */
    public function admin_can_list_orders_with_permission(): void
    {
        $customer = User::factory()->create();
        $this->createPendingOrder($customer, 'LIST-001', 2, 40000);

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'status', 'total_amount', 'created_at', 'customer' => ['name', 'last_name', 'phone'], 'item_count']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'links' => ['first', 'last', 'prev', 'next'],
            ])
            ->assertJsonPath('data.0.item_count', 1)
            ->assertJsonPath('data.0.customer.name', $customer->name)
            ->assertJsonPath('data.0.customer.phone', $customer->phone);
    }

    /** @test */
    public function admin_order_list_can_be_filtered_by_status(): void
    {
        $customer = User::factory()->create();
        $pending = $this->createPendingOrder($customer, 'FIL-001', 1, 10000);
        $paid = $this->createPendingOrder($customer, 'FIL-002', 1, 10000);
        $paid->update(['status' => 'paid']);

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/orders?status=paid')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $paid->id);
    }

    // ── 2. Admin can view order detail ─────────────────────────────────────────

    /** @test */
    public function admin_can_view_order_detail(): void
    {
        $customer = User::factory()->create();
        $order = $this->createPendingOrder($customer, 'DET-001', 3, 25000);

        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.customer.name', $customer->name)
            ->assertJsonPath('data.customer.email', $customer->email)
            ->assertJsonPath('data.items.0.sku', 'DET-001')
            ->assertJsonPath('data.items.0.product_snapshot.title', 'Admin Product')
            ->assertJsonPath('data.items.0.product_snapshot.image_url', '/storage/products/admin.jpg')
            ->assertJsonPath('data.items.0.product_snapshot.attributes.color', 'Black')
            ->assertJsonPath('data.shipment', null); // no shipment until paid
    }

    /** @test */
    public function admin_viewing_missing_order_returns_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/orders/99999')->assertStatus(404);
    }

    // ── 3. Unauthorized user cannot access admin orders ────────────────────────

    /** @test */
    public function customer_cannot_list_admin_orders(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/orders')->assertStatus(403);
    }

    /** @test */
    public function customer_cannot_view_admin_order_detail(): void
    {
        $customer = $this->actingAsCustomer();
        $order = $this->createPendingOrder($customer, 'FORB-001');

        $this->getJson("/api/v1/admin/orders/{$order->id}")->assertStatus(403);
    }

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/admin/orders')->assertStatus(401);
        $this->getJson('/api/v1/admin/orders/1')->assertStatus(401);
        $this->postJson('/api/v1/admin/orders/1/cancel')->assertStatus(401);
    }

    // ── 4 & 5. Admin can cancel; cancellation releases inventory + updates status ──

    /** @test */
    public function admin_can_cancel_a_pending_order_and_release_inventory(): void
    {
        $customer = User::factory()->create();
        $order = $this->createPendingOrder($customer, 'CAN-001', 2, 30000, reserved: 2);

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'CAN-001', 'reserved_quantity' => 0]);
    }

    /** @test */
    public function admin_cannot_cancel_a_non_pending_order(): void
    {
        $customer = User::factory()->create();
        $order = $this->createPendingOrder($customer, 'PAID-001', 1, 30000, reserved: 1);
        $order->update(['status' => 'paid']);

        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        // Reservation untouched — nothing was released.
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'PAID-001', 'reserved_quantity' => 1]);
    }

    /** @test */
    public function customer_cannot_cancel_via_admin_endpoint(): void
    {
        $customer = $this->actingAsCustomer();
        $order = $this->createPendingOrder($customer, 'CFORB-001');

        $this->postJson("/api/v1/admin/orders/{$order->id}/cancel")->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }

    // ── 6. Admin cannot directly change status (no such endpoints exist) ───────

    /** @test */
    public function there_is_no_admin_status_mutation_endpoint(): void
    {
        $customer = User::factory()->create();
        $order = $this->createPendingOrder($customer, 'NOSTAT-001');

        $this->actingAsAdmin();

        // None of these fulfillment/status routes exist on the Order admin surface —
        // status transitions belong to the Shipment module. The detail URI is GET-only,
        // so a mutating verb on it is rejected as 405; invented sub-paths are 404.
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'paid'])->assertStatus(405);
        $this->putJson("/api/v1/admin/orders/{$order->id}", ['status' => 'paid'])->assertStatus(405);
        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'paid'])->assertStatus(404);
        $this->postJson("/api/v1/admin/orders/{$order->id}/change-status", ['status' => 'paid'])->assertStatus(404);
        $this->postJson("/api/v1/admin/orders/{$order->id}/mark-paid")->assertStatus(404);
        $this->postJson("/api/v1/admin/orders/{$order->id}/ship")->assertStatus(404);
        $this->postJson('/api/v1/admin/orders', [])->assertStatus(405); // no create endpoint

        // Status is unchanged by any of the above.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }
}
