<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Modules\Order\Domain\Models\Order;

class ShipmentMethodsTest extends ShipmentTestCase
{
    /** @test */
    public function it_lists_all_enabled_config_backed_methods_without_a_methods_table(): void
    {
        $this->actingAsCustomer();

        $response = $this->getJson('/api/v1/shipment/methods')->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(
            ['post_standard', 'post_express', 'local_delivery', 'in_person_pickup'],
            $codes,
        );
        $this->assertFalse(Schema::hasTable('shipment_methods'));
    }

    /** @test */
    public function methods_use_integer_prices_and_pickup_is_free_without_address(): void
    {
        $this->actingAsCustomer();

        $methods = collect($this->getJson('/api/v1/shipment/methods')->json('data'))->keyBy('code');

        $this->assertSame(850000, $methods['post_standard']['price']);
        $this->assertIsInt($methods['post_standard']['price']);
        $this->assertSame(0, $methods['in_person_pickup']['price']);
        $this->assertFalse($methods['in_person_pickup']['requires_address']);
        $this->assertTrue($methods['post_standard']['requires_address']);
        $this->assertTrue($methods['local_delivery']['requires_delivery_slot']);
        $this->assertFalse($methods['post_standard']['requires_delivery_slot']);
        $this->assertArrayHasKey('pickup_location', $methods['in_person_pickup']);
    }

    // ── Checkout validation matrix ─────────────────────────────────────────────

    /** @test */
    public function standard_post_succeeds_with_a_valid_address(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('SP-1', 40000, 5);
        $this->addToCart($user->id, 'SP-1', 1);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])
            ->assertStatus(201)
            ->assertJsonPath('shipment_method_code', 'post_standard')
            ->assertJsonPath('shipping_cost', 850000)
            ->assertJsonPath('total_amount', 40000 + 850000);
    }

    /** @test */
    public function standard_post_rejects_missing_address(): void
    {
        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('SP-2');
        $this->addToCart($user->id, 'SP-2', 1);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_standard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    /** @test */
    public function postal_methods_reject_a_delivery_slot(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('SP-3');
        $this->addToCart($user->id, 'SP-3', 1);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_express',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_slot_id');
    }

    /** @test */
    public function local_delivery_requires_address_and_slot(): void
    {
        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('LD-1');
        $this->addToCart($user->id, 'LD-1', 1);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'local_delivery'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');

        $addressId = $this->createAddress($user->id);
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_slot_id');
    }

    /** @test */
    public function local_delivery_succeeds_and_holds_the_slot(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('LD-2', 30000, 5);
        $this->addToCart($user->id, 'LD-2', 1);

        $response = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])
            ->assertStatus(201)
            ->assertJsonPath('shipment_method_code', 'local_delivery')
            ->assertJsonPath('shipping_cost', 1200000);

        $orderId = $response->json('id');
        $this->assertDatabaseHas('delivery_slot_reservations', [
            'delivery_slot_id' => $slot->id,
            'order_id' => $orderId,
            'status' => 'held',
        ]);
    }

    /** @test */
    public function local_delivery_rejects_an_ineligible_address(): void
    {
        [$provinceId, $cityId] = $this->createProvinceCity();
        Config::set('shipment.local_delivery.city_ids', [$cityId + 100]);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, provinceId: $provinceId, cityId: $cityId);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('LD-3');
        $this->addToCart($user->id, 'LD-3', 1);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    /** @test */
    public function pickup_succeeds_without_address_and_rejects_a_slot(): void
    {
        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('PU-1', 25000, 5);
        $this->addToCart($user->id, 'PU-1', 1);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertStatus(201)
            ->assertJsonPath('shipping_cost', 0)
            ->assertJsonPath('total_amount', 25000);

        // A pickup that supplies a delivery slot is rejected.
        $this->addToCart($user->id, 'PU-1', 1);
        $slot = $this->createBookableSlot();
        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'in_person_pickup',
            'delivery_slot_id' => $slot->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_slot_id');
    }

    /** @test */
    public function unknown_method_code_returns_a_validation_error(): void
    {
        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('UM-1');
        $this->addToCart($user->id, 'UM-1', 1);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'teleport'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
    }

    /** @test */
    public function disabled_method_cannot_be_selected(): void
    {
        Config::set('shipment.methods.post_standard.enabled', false);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('DM-1');
        $this->addToCart($user->id, 'DM-1', 1);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
    }

    // ── Snapshot immutability ──────────────────────────────────────────────────

    /** @test */
    public function order_snapshot_is_immutable_when_config_changes_later(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $this->createVariantWithStock('SNAP-1', 10000, 5);
        $this->addToCart($user->id, 'SNAP-1', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $addressId,
        ])->json('id');

        // Reprice the method after the order exists.
        Config::set('shipment.methods.post_standard.price', 5_000_000);

        $order = Order::find($orderId);
        $this->assertSame(850000, $order->shipment_snapshot['shipping_cost']);
        $this->assertSame(850000, $order->shipping_cost);
        $this->assertSame('Standard Post', $order->shipment_snapshot['method_title']);
    }

    /** @test */
    public function frontend_cannot_override_shipping_cost_or_slot_datetime(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id);
        $slot = $this->createBookableSlot();
        $this->createVariantWithStock('OVR-1', 10000, 5);
        $this->addToCart($user->id, 'OVR-1', 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $addressId,
            'delivery_slot_id' => $slot->id,
            'shipping_cost' => 1,
            'delivery_slot' => ['date' => '1999-01-01', 'starts_at' => '00:00:00'],
        ])->assertStatus(201)->json('id');

        $order = Order::find($orderId);
        $this->assertSame(1200000, $order->shipping_cost);
        $this->assertSame($slot->dateString(), $order->shipment_snapshot['delivery_slot']['date']);
    }
}
