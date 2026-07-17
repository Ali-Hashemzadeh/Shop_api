<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Carbon\Carbon;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;
use Tests\Feature\Shipment\ShipmentTestCase;

class OrderQuantityLimitTest extends ShipmentTestCase
{
    private function variant(string $sku, ?int $limit, int $stock = 30): ProductVariant
    {
        $product = Product::create([
            'title' => "Product {$sku}",
            'slug' => strtolower($sku),
            'status' => 'published',
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'type' => 'color',
            'base_price' => 50000,
            'is_default' => true,
            'attributes' => [],
            'max_quantity_per_order' => $limit,
        ]);

        InventoryStock::create(['sku' => $sku, 'quantity' => $stock, 'reserved_quantity' => 0]);

        return $variant;
    }

    private function checkout(int $quantity, ?int $limit = 5, string $sku = 'ORDER-LIMIT')
    {
        $user = $this->actingAsCustomer();
        $this->variant($sku, $limit);
        $this->addToCart($user->id, $sku, $quantity);

        return [$user, $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])];
    }

    public function test_checkout_below_and_exact_limit_succeeds_and_snapshots_limit(): void
    {
        [$belowUser, $below] = $this->checkout(4, 5, 'BELOW');
        $below->assertCreated()
            ->assertJsonPath('items.0.max_quantity_per_order_snapshot', 5);
        $this->assertDatabaseHas('order_items', [
            'sku' => 'BELOW',
            'quantity' => 4,
            'max_quantity_per_order_snapshot' => 5,
        ]);

        app(OrderManagerInterface::class)->markAsPaid(Order::where('user_id', $belowUser->id)->value('id'), 'BELOW-PAID');

        [$exactUser, $exact] = $this->checkout(5, 5, 'EXACT');
        $exact->assertCreated();
        $this->assertDatabaseHas('order_items', [
            'sku' => 'EXACT',
            'quantity' => 5,
            'max_quantity_per_order_snapshot' => 5,
        ]);
    }

    public function test_checkout_above_current_limit_has_no_mutation_or_reservation_and_keeps_cart(): void
    {
        [$user, $response] = $this->checkout(6);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('items.ORDER-LIMIT.quantity');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('delivery_slot_reservations', 0);
        $this->assertDatabaseHas('inventory_stocks', [
            'sku' => 'ORDER-LIMIT',
            'reserved_quantity' => 0,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => Cart::where('user_id', $user->id)->value('id'),
            'sku' => 'ORDER-LIMIT',
            'quantity' => 6,
        ]);
    }

    public function test_admin_lowering_blocks_checkout_raising_allows_it_and_clearing_restores_stock_only_behavior(): void
    {
        $user = $this->actingAsCustomer();
        $variant = $this->variant('CURRENT-RULE', 10);
        $this->addToCart($user->id, 'CURRENT-RULE', 8);

        $variant->update(['max_quantity_per_order' => 5]);
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertUnprocessable();

        $variant->update(['max_quantity_per_order' => 8]);
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertCreated();

        $order = Order::latest('id')->firstOrFail();
        app(OrderManagerInterface::class)->markAsPaid($order->id, 'CURRENT-PAID');
        $variant->update(['max_quantity_per_order' => null]);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertCreated()
            ->assertJsonPath('items.0.max_quantity_per_order_snapshot', null);
    }

    public function test_quantity_failure_does_not_cancel_an_existing_pending_order(): void
    {
        $user = $this->actingAsCustomer();
        $firstVariant = $this->variant('FIRST-PENDING', 5);
        $cart = $this->addToCart($user->id, 'FIRST-PENDING', 1);
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])->assertCreated();
        $pending = Order::firstOrFail();

        CartItem::where('cart_id', $cart->id)->delete();
        $secondVariant = $this->variant('SECOND-INVALID', 2);
        CartItem::create(['cart_id' => $cart->id, 'sku' => $secondVariant->sku, 'quantity' => 3]);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('orders', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => $firstVariant->sku, 'reserved_quantity' => 1]);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => $secondVariant->sku, 'reserved_quantity' => 0]);
    }

    public function test_failed_local_delivery_checkout_creates_no_slot_hold(): void
    {
        [$provinceId, $cityId] = $this->createProvinceCity();
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $provinceId, $cityId);
        $this->variant('LOCAL-INVALID', 2);
        $this->addToCart($user->id, 'LOCAL-INVALID', 3);
        $slot = $this->createBookableSlot();
        DeliveryWorkingPeriod::create([
            'weekday' => Carbon::parse($slot->delivery_date)->dayOfWeek,
            'starts_at' => $slot->starts_at,
            'ends_at' => $slot->ends_at,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('delivery_slot_reservations', 0);
    }

    public function test_previous_orders_are_not_counted_and_historical_snapshot_does_not_change(): void
    {
        $user = $this->actingAsCustomer();
        $variant = $this->variant('REPEAT', 5, 30);
        $this->addToCart($user->id, 'REPEAT', 5);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])->assertCreated();
        $first = Order::with('items')->firstOrFail();
        app(OrderManagerInterface::class)->markAsPaid($first->id, 'FIRST-PAID');

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])->assertCreated();
        $second = Order::latest('id')->firstOrFail();
        app(OrderManagerInterface::class)->markAsPaid($second->id, 'SECOND-PAID');

        $variant->update(['max_quantity_per_order' => 2]);

        $this->assertDatabaseCount('orders', 2);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $first->id,
            'quantity' => 5,
            'max_quantity_per_order_snapshot' => 5,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $second->id,
            'quantity' => 5,
            'max_quantity_per_order_snapshot' => 5,
        ]);
    }
}
