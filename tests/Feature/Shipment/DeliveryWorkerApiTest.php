<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Models\User;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;

/**
 * The courier's own surface: scoped to their assignments, and carrying only what
 * a doorstep delivery needs.
 */
class DeliveryWorkerApiTest extends ShipmentTestCase
{
    private FakeSmsProvider $sms;

    /** One shared, roomy slot: several couriers in one test book the same window. */
    private ?int $slotId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();
        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    /** A paid local-delivery shipment assigned to the given driver. */
    private function assignedShipment(User $driver): Shipment
    {
        $customer = $this->actingAsOperator();
        $customer->forceFill([
            'phone' => '0912'.str_pad((string) $customer->id, 7, '0', STR_PAD_LEFT),
            'name' => 'Sara',
            'last_name' => 'Ahmadi',
        ])->save();

        $sku = 'DRIVER-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($customer->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $this->createAddress($customer->id),
            'delivery_slot_id' => $this->slotId ??= $this->createBookableSlot(capacity: 10)->id,
        ])->assertStatus(201)->json('id');

        $this->markOrderPaid($orderId);

        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $this->assignDriver($shipment->id, $driver, (int) $customer->id);

        return $shipment->fresh();
    }

    /** @test */
    public function a_driver_sees_only_their_own_assigned_local_shipments(): void
    {
        $driver = $this->createDeliveryUser();
        $other = $this->createDeliveryUser();

        $mine = $this->assignedShipment($driver);
        $theirs = $this->assignedShipment($other);

        $this->actingAs($driver, 'sanctum');

        $codes = collect($this->getJson('/api/v1/delivery/shipments')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($codes->contains($mine->public_code));
        $this->assertFalse($codes->contains($theirs->public_code));
        $this->assertCount(1, $codes);
    }

    /** @test */
    public function the_list_is_paginated(): void
    {
        $driver = $this->createDeliveryUser();
        $this->assignedShipment($driver);

        $this->actingAs($driver, 'sanctum');

        $this->getJson('/api/v1/delivery/shipments?per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    /** @test */
    public function another_drivers_shipment_is_indistinguishable_from_a_nonexistent_one(): void
    {
        $driver = $this->createDeliveryUser();
        $other = $this->createDeliveryUser();
        $theirs = $this->assignedShipment($other);

        $this->actingAs($driver, 'sanctum');

        // A real shipment they do not hold, and a code that never existed, must
        // give the same answer — otherwise the difference is an existence oracle.
        $this->getJson("/api/v1/delivery/shipments/{$theirs->public_code}")->assertStatus(404);
        $this->getJson('/api/v1/delivery/shipments/bds-ZZZZZZ')->assertStatus(404);

        $this->postJson("/api/v1/delivery/shipments/{$theirs->public_code}/mark-delivered", ['code' => '123456'])
            ->assertStatus(404);
    }

    /** @test */
    public function the_driver_detail_carries_what_a_delivery_needs_and_nothing_financial(): void
    {
        $driver = $this->createDeliveryUser();
        $shipment = $this->assignedShipment($driver);
        $customerPhone = User::findOrFail($shipment->user_id)->phone;

        $this->actingAs($driver, 'sanctum');

        $response = $this->getJson("/api/v1/delivery/shipments/{$shipment->public_code}")->assertOk();

        $response
            ->assertJsonPath('id', $shipment->public_code)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('customer_name', 'Sara Ahmadi')
            ->assertJsonPath('customer_phone', $customerPhone)
            ->assertJsonPath('address.address', '123 Test Street');

        $this->assertNotNull($response->json('delivery_slot.date'));
        $this->assertNotNull($response->json('assigned_at'));

        // The frozen pin, so the courier navigates rather than guesses.
        $this->assertSame('35.7000000', $response->json('address.latitude'));
        $this->assertSame('51.4000000', $response->json('address.longitude'));
        $this->assertSame('Pinned at 123 Test Street', $response->json('address.map_address'));

        // Nothing about money, and nothing about the code.
        $payload = $response->json();
        foreach ([
            'total_amount', 'shipping_cost', 'coupon_code', 'coupon_discount_amount',
            'payment', 'delivery_verification_code_hash', 'code', 'delivery_code',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }

        $this->assertStringNotContainsString('verification', strtolower(json_encode($payload)));
    }

    /** @test */
    public function editing_the_address_later_does_not_move_an_already_placed_delivery(): void
    {
        $driver = $this->createDeliveryUser();
        $shipment = $this->assignedShipment($driver);

        // The customer moves house after checkout.
        DB::table('addresses')
            ->where('id', $shipment->address_snapshot['address_id'])
            ->update(['address' => 'Somewhere else entirely', 'latitude' => '0.0000000']);

        $this->actingAs($driver, 'sanctum');

        $this->getJson("/api/v1/delivery/shipments/{$shipment->public_code}")
            ->assertOk()
            ->assertJsonPath('address.address', '123 Test Street')
            ->assertJsonPath('address.latitude', '35.7000000');
    }

    /** @test */
    public function a_driver_cannot_reach_the_admin_shipment_surface(): void
    {
        $driver = $this->createDeliveryUser();
        $shipment = $this->assignedShipment($driver);

        $this->actingAs($driver, 'sanctum');

        $this->getJson('/api/v1/admin/shipments')->assertStatus(403);
        $this->getJson("/api/v1/admin/shipments/{$shipment->public_code}")->assertStatus(403);
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-out-for-delivery")->assertStatus(403);
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")->assertStatus(403);
    }

    /** @test */
    public function the_driver_surface_requires_authentication_and_the_assigned_permissions(): void
    {
        $driver = $this->createDeliveryUser();
        $shipment = $this->assignedShipment($driver);

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/delivery/shipments')->assertStatus(401);

        // A plain shopper holds neither delivery permission.
        $shopper = User::factory()->create(['phone' => '09140000001']);
        $shopper->assignRole('customer');
        $this->actingAs($shopper, 'sanctum');

        $this->getJson('/api/v1/delivery/shipments')->assertStatus(403);
        $this->getJson("/api/v1/delivery/shipments/{$shipment->public_code}")->assertStatus(403);
        $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", ['code' => '123456'])
            ->assertStatus(403);
    }
}
