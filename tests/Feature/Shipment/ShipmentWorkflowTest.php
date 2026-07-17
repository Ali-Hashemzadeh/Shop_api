<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;

class ShipmentWorkflowTest extends ShipmentTestCase
{
    /** Create a paid order + active shipment for the given method, return the shipment. */
    private function paidShipment(string $methodCode, array $extra = []): Shipment
    {
        $user = $extra['user'] ?? $this->actingAsOperator();
        $payload = ['shipment_method_code' => $methodCode];

        if ($methodCode !== 'in_person_pickup') {
            $payload['address_id'] = $this->createAddress($user->id);
        }
        if ($methodCode === 'local_delivery') {
            $payload['delivery_slot_id'] = ($extra['slot'] ?? $this->createBookableSlot())->id;
        }

        $sku = 'WF-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($user->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', $payload)->assertStatus(201)->json('id');
        $this->markOrderPaid($orderId);

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    private function code(Shipment $s): string
    {
        return $s->public_code;
    }

    // ── Postal ─────────────────────────────────────────────────────────────────

    /** @test */
    public function postal_workflow_reaches_handed_to_post_and_marks_order_shipped(): void
    {
        $shipment = $this->paidShipment('post_standard');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")
            ->assertOk()->assertJsonPath('status', 'preparing');
        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'processing']);

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-post")
            ->assertOk()->assertJsonPath('status', 'ready_for_post');

        $this->postJson("/api/v1/admin/shipments/{$code}/hand-to-post", ['tracking_number' => 'TRK-999'])
            ->assertOk()
            ->assertJsonPath('status', 'handed_to_post')
            ->assertJsonPath('tracking_number', 'TRK-999');

        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'shipped']);
    }

    /** @test */
    public function hand_to_post_requires_a_tracking_number(): void
    {
        $shipment = $this->paidShipment('post_express');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-post")->assertOk();

        $this->postJson("/api/v1/admin/shipments/{$code}/hand-to-post", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tracking_number');
    }

    /** @test */
    public function postal_shipment_cannot_use_local_delivery_transitions(): void
    {
        $shipment = $this->paidShipment('post_standard');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        // out_for_delivery is not a valid postal transition.
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-out-for-delivery")->assertStatus(422);
    }

    /** @test */
    public function invalid_transition_from_pending_straight_to_handoff_is_rejected(): void
    {
        $shipment = $this->paidShipment('post_standard');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/hand-to-post", ['tracking_number' => 'X'])
            ->assertStatus(422);
    }

    // ── Local delivery ─────────────────────────────────────────────────────────

    /** @test */
    public function local_delivery_workflow_completes_and_marks_order_completed(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-dispatch")->assertOk()->assertJsonPath('status', 'ready_for_dispatch');
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-out-for-delivery")->assertOk()->assertJsonPath('status', 'out_for_delivery');
        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'shipped']);

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-delivered", ['receiver_name' => 'Ali'])
            ->assertOk()->assertJsonPath('status', 'delivered');

        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'completed']);
        $this->assertDatabaseHas('delivery_slot_reservations', ['order_id' => $shipment->order_id, 'status' => 'completed']);
    }

    /** @test */
    public function delivery_failure_requires_a_reason_and_reschedule_moves_to_a_new_slot(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-dispatch")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-out-for-delivery")->assertOk();

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-delivery-failed", [])
            ->assertStatus(422)->assertJsonValidationErrors('failure_reason');

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-delivery-failed", ['failure_reason' => 'customer_unavailable'])
            ->assertOk()->assertJsonPath('status', 'delivery_failed');

        $newSlot = $this->createBookableSlot(start: '18:00:00', end: '19:30:00');
        $this->postJson("/api/v1/admin/shipments/{$code}/reschedule", ['delivery_slot_id' => $newSlot->id])
            ->assertOk()->assertJsonPath('status', 'ready_for_dispatch');

        $this->assertDatabaseHas('delivery_slot_reservations', ['delivery_slot_id' => $newSlot->id, 'order_id' => $shipment->order_id, 'status' => 'confirmed']);
    }

    // ── Pickup ─────────────────────────────────────────────────────────────────

    /** @test */
    public function pickup_workflow_completes_and_marks_order_completed(): void
    {
        $shipment = $this->paidShipment('in_person_pickup');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-pickup")->assertOk()->assertJsonPath('status', 'ready_for_pickup');
        $this->postJson("/api/v1/admin/shipments/{$code}/confirm-pickup", ['receiver_name' => 'Sara'])
            ->assertOk()->assertJsonPath('status', 'picked_up');

        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'completed']);
    }

    /** @test */
    public function pickup_cannot_use_postal_transitions(): void
    {
        $shipment = $this->paidShipment('in_person_pickup');
        $code = $this->code($shipment);

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-post")->assertStatus(422);
    }

    // ── Concurrency ────────────────────────────────────────────────────────────

    /** @test */
    public function two_orders_cannot_both_consume_the_final_slot_place(): void
    {
        $slot = $this->createBookableSlot(capacity: 1);

        // First customer takes the only place.
        $u1 = $this->actingAsCustomer();
        $addr1 = $this->createAddress($u1->id);
        $this->createVariantWithStock('CC-1', 10000, 5);
        $this->addToCart($u1->id, 'CC-1', 1);
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addr1,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(201);

        // Second customer, stale availability, tries the same slot.
        $u2 = $this->actingAsCustomer();
        $addr2 = $this->createAddress($u2->id);
        $this->createVariantWithStock('CC-2', 10000, 5);
        $this->addToCart($u2->id, 'CC-2', 1);
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addr2,
            'delivery_slot_id' => $slot->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_slot_id');

        $active = DeliverySlotReservation::whereIn('status', ['held', 'confirmed'])->count();
        $this->assertSame(1, $active);
    }
}
