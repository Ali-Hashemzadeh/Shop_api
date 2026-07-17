<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;

class ShipmentPaymentIntegrationTest extends ShipmentTestCase
{
    private function checkoutPickup(int $userId, string $sku = 'PAY-1', int $qty = 1): int
    {
        $this->createVariantWithStock($sku, 20000, 10);
        $this->addToCart($userId, $sku, $qty);

        return $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertStatus(201)->json('id');
    }

    /** @test */
    public function no_shipment_record_exists_before_payment(): void
    {
        $user = $this->actingAsCustomer();
        $orderId = $this->checkoutPickup($user->id);

        $this->assertDatabaseCount('shipments', 0);
        $this->assertNull(Shipment::where('order_id', $orderId)->first());
    }

    /** @test */
    public function successful_payment_creates_exactly_one_shipment_and_is_idempotent(): void
    {
        $user = $this->actingAsCustomer();
        $orderId = $this->checkoutPickup($user->id);

        $this->markOrderPaid($orderId);
        $this->markOrderPaid($orderId); // repeated callback must not duplicate

        $this->assertDatabaseCount('shipments', 1);
        $shipment = Shipment::where('order_id', $orderId)->first();
        $this->assertSame('pending', $shipment->status);
        $this->assertSame('in_person_pickup', $shipment->method_code);
        // Exactly one initial status-history row.
        $this->assertDatabaseCount('shipment_status_histories', 1);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'paid']);
    }

    /** @test */
    public function inventory_is_committed_exactly_once_on_payment(): void
    {
        $user = $this->actingAsCustomer();
        $orderId = $this->checkoutPickup($user->id, 'PAY-COMMIT', 3);

        // Reserved after checkout.
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'PAY-COMMIT', 'quantity' => 10, 'reserved_quantity' => 3]);

        $this->markOrderPaid($orderId);
        $this->markOrderPaid($orderId);

        // Committed once: physical and reserved both dropped by 3, never twice.
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'PAY-COMMIT', 'quantity' => 7, 'reserved_quantity' => 0]);
    }

    /** @test */
    public function local_delivery_hold_becomes_confirmed_on_payment(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('PAY-LD', 30000, 5);
        $this->addToCart($user->id, 'PAY-LD', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(201)->json('id');

        $this->assertDatabaseHas('delivery_slot_reservations', ['order_id' => $orderId, 'status' => 'held']);

        $this->markOrderPaid($orderId);

        $this->assertDatabaseHas('delivery_slot_reservations', ['order_id' => $orderId, 'status' => 'confirmed']);
    }

    /** @test */
    public function expired_pending_order_releases_stock_and_slot_and_creates_no_shipment(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('EXP-LD', 30000, 5);
        $this->addToCart($user->id, 'EXP-LD', 2);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(201)->json('id');

        Order::where('id', $orderId)->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('orders:cancel-expired')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'EXP-LD', 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('delivery_slot_reservations', ['order_id' => $orderId, 'status' => 'released']);
        $this->assertDatabaseCount('shipments', 0);
    }

    /** @test */
    public function replacing_a_pending_local_order_releases_the_old_slot_hold(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot(capacity: 3);
        $this->createVariantWithStock('REP-1', 10000, 20);

        $this->addToCart($user->id, 'REP-1', 1);
        $firstOrderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(201)->json('id');

        // A second checkout replaces the first pending order.
        $this->addToCart($user->id, 'REP-1', 1);
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('delivery_slot_reservations', ['order_id' => $firstOrderId, 'status' => 'released']);
        // Only the new hold consumes capacity.
        $active = DeliverySlotReservation::whereIn('status', ['held', 'confirmed'])->count();
        $this->assertSame(1, $active);
    }
}
