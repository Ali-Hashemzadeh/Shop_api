<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Shipment\Domain\Models\Shipment;

class ShipmentAuthorizationTest extends ShipmentTestCase
{
    private function paidPickupShipmentFor(User $user): Shipment
    {
        $sku = 'AUTH-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($user->id, $sku, 1);
        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertStatus(201)->json('id');
        $this->markOrderPaid($orderId);

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    /** @test */
    public function guests_receive_401_on_shipment_routes(): void
    {
        $this->getJson('/api/v1/shipment/methods')->assertStatus(401);
        $this->getJson('/api/v1/shipments/SH-ABC')->assertStatus(401);
        $this->getJson('/api/v1/admin/shipments')->assertStatus(401);
        $this->postJson('/api/v1/admin/shipments/SH-ABC/start-preparing')->assertStatus(401);
    }

    /** @test */
    public function customer_can_view_their_own_shipment(): void
    {
        $user = $this->actingAsCustomer();
        $shipment = $this->paidPickupShipmentFor($user);

        $this->getJson("/api/v1/shipments/{$shipment->public_code}")
            ->assertOk()
            ->assertJsonPath('id', $shipment->public_code)
            ->assertJsonPath('method_code', 'in_person_pickup');

        $this->getJson("/api/v1/orders/{$shipment->order_id}/shipment")
            ->assertOk()
            ->assertJsonPath('id', $shipment->public_code);
    }

    /** @test */
    public function customer_cannot_view_another_users_shipment(): void
    {
        $owner = $this->actingAsCustomer();
        $shipment = $this->paidPickupShipmentFor($owner);

        $this->actingAsCustomer(); // a different authenticated customer

        $this->getJson("/api/v1/shipments/{$shipment->public_code}")->assertStatus(403);
    }

    /** @test */
    public function user_without_permission_receives_403_on_admin_action(): void
    {
        $user = $this->actingAsCustomer();
        $shipment = $this->paidPickupShipmentFor($user);

        // Customer role lacks shipment.start-preparing.
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/start-preparing")
            ->assertStatus(403);
    }

    /** @test */
    public function permission_works_independently_of_role(): void
    {
        // A bare user (no admin role) granted only the specific permission may act.
        $owner = $this->actingAsCustomer();
        $shipment = $this->paidPickupShipmentFor($owner);

        $operator = User::factory()->create();
        $operator->givePermissionTo('shipment.start-preparing');
        Sanctum::actingAs($operator);

        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/start-preparing")
            ->assertOk()
            ->assertJsonPath('status', 'preparing');
    }
}
