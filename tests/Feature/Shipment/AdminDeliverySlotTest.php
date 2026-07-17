<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Modules\Shipment\Domain\Models\DeliverySlotReservation;

class AdminDeliverySlotTest extends ShipmentTestCase
{
    private function reserve(int $slotId, string $status = 'confirmed', int $orderId = 500): void
    {
        DeliverySlotReservation::create([
            'delivery_slot_id' => $slotId,
            'order_id' => $orderId,
            'user_id' => 1,
            'status' => $status,
        ]);
    }

    /** @test */
    public function operator_can_close_a_slot_and_it_becomes_unbookable(): void
    {
        $this->actingAsOperator();
        $slot = $this->createBookableSlot();

        $this->postJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}/close")
            ->assertOk()->assertJsonPath('status', 'closed');

        // A customer cannot book a closed slot at checkout.
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('CS-1', 10000, 5);
        $this->addToCart($user->id, 'CS-1', 1);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])->assertStatus(422)->assertJsonValidationErrors('delivery_slot_id');
    }

    /** @test */
    public function operator_can_reopen_a_closed_slot(): void
    {
        $this->actingAsOperator();
        $slot = $this->createBookableSlot(status: 'closed');

        $this->postJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}/open")
            ->assertOk()->assertJsonPath('status', 'open');
    }

    /** @test */
    public function operator_can_reserve_part_of_capacity(): void
    {
        $this->actingAsOperator();
        $slot = $this->createBookableSlot(capacity: 3);

        $this->patchJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}", [
            'admin_reserved_capacity' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('admin_reserved_capacity', 2)
            ->assertJsonPath('remaining_capacity', 1);
    }

    /** @test */
    public function operator_cannot_reduce_effective_capacity_below_active_reservations(): void
    {
        $this->actingAsOperator();
        $slot = $this->createBookableSlot(capacity: 3);
        $this->reserve($slot->id, 'confirmed', 601);
        $this->reserve($slot->id, 'held', 602);

        // Two active reservations; capacity 1 would leave effective < active.
        $this->patchJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}", ['capacity' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacity');
    }

    /** @test */
    public function admin_slot_index_returns_paginator_envelope(): void
    {
        $this->actingAsOperator();
        $this->createBookableSlot();

        $this->getJson('/api/v1/admin/shipment/delivery-slots')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.per_page', 15);
    }

    /** @test */
    public function slot_management_requires_permission(): void
    {
        $this->actingAsCustomer();
        $slot = $this->createBookableSlot();

        $this->getJson('/api/v1/admin/shipment/delivery-slots')->assertStatus(403);
        $this->postJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}/close")->assertStatus(403);
        $this->patchJson("/api/v1/admin/shipment/delivery-slots/{$slot->id}", ['capacity' => 5])->assertStatus(403);
    }
}
