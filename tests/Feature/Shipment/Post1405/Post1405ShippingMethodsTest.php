<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment\Post1405;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Models\Shipment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

/**
 * GET /api/v1/shipment/methods and POST /api/v1/orders with the Iran Post 1405
 * engine: every enabled method carries its own price, unavailable methods never get a
 * price, and checkout recalculates with exactly the same rules.
 */
class Post1405ShippingMethodsTest extends ShipmentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPost1405();
    }

    private function methods(int $addressId): Collection
    {
        return collect($this->getJson("/api/v1/shipment/methods?address_id={$addressId}")->assertOk()->json('data'))->keyBy('code');
    }

    /** @return array{0: int, 1: int} user id, address id */
    private function customerWithCart(int $cityId, int $unitGrams, int $qty = 1, int $price = 50000): array
    {
        $user = $this->actingAsCustomer();
        $sku = 'P-'.uniqid();
        $this->createWeightedVariant($sku, $unitGrams, $price, 50);
        $this->addToCart($user->id, $sku, $qty);

        return [$user->id, $this->postalAddress($user->id, $cityId)];
    }

    // ── Method list ────────────────────────────────────────────────────────────

    #[Test]
    public function the_spec_example_d3_parcel_to_ahvaz_is_priced_and_itemised(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);

        $d3 = $this->methods($addressId)['post_intercity_d3_parcel'];

        $this->assertSame([
            'code' => 'post_intercity_d3_parcel',
            'title' => 'پیشتاز D+3 — بسته',
            'type' => 'postal',
            'price' => 176500,
            'requires_address' => true,
            'requires_delivery_slot' => false,
            'supports_tracking' => true,
            'estimated_min_days' => null,
            'estimated_max_days' => null,
            'available' => true,
            'unavailable_reason' => null,
            'unavailable_code' => null,
            'currency' => 'TOMAN',
            'pricing_mode' => 'post_1405_tariff',
            'tariff_year' => 1405,
            'scope' => 'intercity',
            'package_kind' => 'parcel',
            'weight_grams' => 910,
            'rate_zone' => 'non_neighbor',
            'price_breakdown' => [
                'base_price' => 100000,
                'extra_weight' => 0,
                'delivery_surcharge' => 15000,
                'large_province_surcharge' => 0,
                'island_surcharge' => 0,
                'fragile_surcharge' => 0,
                'electronic_service_charge' => 0,
                'compensation' => 0,
                'postal_tax' => 11500,
                'packaging' => 50000,
                'total' => 176500,
            ],
        ], $d3);
    }

    #[Test]
    public function every_available_postal_method_carries_its_own_calculated_price(): void
    {
        // 2.5 kg to Shiraz with D+1 and D+2 coverage confirmed for Fars.
        config([
            'shipping.post_1405.coverage.intercity_d1.province_ids' => [17],
            'shipping.post_1405.coverage.intercity_d2.province_ids' => [17],
        ]);
        [, $addressId] = $this->customerWithCart(self::SHIRAZ, 2500);

        $methods = $this->methods($addressId);
        $available = $methods->where('type', 'postal')->where('available', true);

        $this->assertEqualsCanonicalizing(
            ['post_registered_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'],
            $available->keys()->all(),
        );

        // Each price is its own breakdown total — and they all differ.
        foreach ($available as $method) {
            $this->assertIsInt($method['price']);
            $this->assertSame($method['price'], $method['price_breakdown']['total']);
            $this->assertSame(50000, $method['price_breakdown']['packaging']);
        }
        $this->assertCount(4, $available->pluck('price')->unique());

        // Registered parcel non-neighbour 2–3 kg: 44,900 + 15 % + 5 % + 7,700 e-service, 10 % tax, packaging.
        $this->assertSame(44900 + 6735 + 2245 + 7700 + 6158 + 50000, $methods['post_registered_parcel']['price']);
        $this->assertSame(7700, $methods['post_registered_parcel']['price_breakdown']['electronic_service_charge']);
    }

    #[Test]
    public function unavailable_postal_methods_have_a_null_price_and_a_reason_code(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);

        $methods = $this->methods($addressId);

        // 910 g is below registered parcel's first (2 kg+) bracket.
        $this->assertFalse($methods['post_registered_parcel']['available']);
        $this->assertSame('tariff_missing', $methods['post_registered_parcel']['unavailable_code']);
        // No route coverage configured.
        foreach (['post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_sameday_parcel', 'post_urban_3h_parcel', 'post_urban_sameday_parcel', 'post_urban_nextday_parcel'] as $code) {
            $this->assertFalse($methods[$code]['available'], $code);
            $this->assertSame('route_not_supported', $methods[$code]['unavailable_code'], $code);
        }

        foreach ($methods->where('available', false)->where('type', 'postal') as $method) {
            $this->assertNull($method['price']);
            $this->assertNull($method['price_breakdown']);
            $this->assertNotNull($method['unavailable_reason']);
        }
    }

    #[Test]
    public function envelope_services_are_off_by_default_and_never_priced_for_goods_when_enabled(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 400);

        $methods = $this->methods($addressId);
        foreach ($methods->keys() as $code) {
            $this->assertStringNotContainsString('envelope', $code);
            $this->assertNotContains($code, ['post_economy_unregistered', 'post_registered_document', 'post_registered_packet', 'post_mbag_unregistered', 'post_mbag_registered', 'post_periodical']);
        }

        config(['shipping.post_1405.services.post_intercity_d3_envelope' => true]);
        $envelope = $this->methods($addressId)['post_intercity_d3_envelope'];
        $this->assertFalse($envelope['available']);
        $this->assertSame('content_not_eligible', $envelope['unavailable_code']);
        $this->assertNull($envelope['price']);
    }

    #[Test]
    public function every_parcel_method_has_its_own_toggle(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);

        foreach (['post_registered_parcel', 'post_urban_3h_parcel', 'post_urban_sameday_parcel', 'post_urban_nextday_parcel', 'post_intercity_sameday_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'] as $code) {
            config(["shipping.post_1405.services.{$code}" => false]);
            $this->assertArrayNotHasKey($code, $this->methods($addressId)->all(), $code);
            config(["shipping.post_1405.services.{$code}" => true]);
        }
    }

    #[Test]
    public function a_missing_product_weight_makes_postal_unavailable_and_names_the_sku_when_the_fallback_is_disabled(): void
    {
        // With the Normal Post fallback off, the original fail-closed behaviour holds
        // (the enabled fallback is covered by MissingWeightNormalPostFallbackTest).
        config(['shipping.missing_weight_fallback.enabled' => false]);
        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('HAS-W', 300);
        $this->createWeightedVariant('NO-W', null);
        $this->addToCart($user->id, 'HAS-W');
        $this->addToCart($user->id, 'NO-W');
        $addressId = $this->postalAddress($user->id, self::AHVAZ);

        $d3 = $this->methods($addressId)['post_intercity_d3_parcel'];

        $this->assertFalse($d3['available']);
        $this->assertNull($d3['price']);
        $this->assertSame('weight_missing', $d3['unavailable_code']);
        $this->assertSame(['NO-W'], $d3['missing_weight_skus']);
        // Local delivery and pickup are unaffected by weights.
        $this->assertSame((int) config('shipment.methods.in_person_pickup.price'), $this->methods($addressId)['in_person_pickup']['price']);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_parcel', 'address_id' => $addressId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.NO-W.weight_grams')
            ->assertJsonMissingValidationErrors('items.HAS-W.weight_grams');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('inventory_stocks', ['sku' => 'NO-W', 'reserved_quantity' => 0]);
    }

    #[Test]
    public function an_empty_cart_gets_no_postal_price(): void
    {
        $user = $this->actingAsCustomer();
        $addressId = $this->postalAddress($user->id, self::AHVAZ);

        $d3 = $this->methods($addressId)['post_intercity_d3_parcel'];

        $this->assertFalse($d3['available']);
        $this->assertSame('cart_empty', $d3['unavailable_code']);
        $this->assertNull($d3['price']);
    }

    #[Test]
    public function an_address_without_a_city_is_not_priced(): void
    {
        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('NOCITY', 500);
        $this->addToCart($user->id, 'NOCITY');
        $addressId = $this->createAddress($user->id, 13, null);

        $this->assertSame('destination_incomplete', $this->methods($addressId)['post_intercity_d3_parcel']['unavailable_code']);
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_parcel', 'address_id' => $addressId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');
    }

    #[Test]
    public function local_delivery_and_pickup_carry_no_packaging_or_breakdown(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);

        $methods = $this->methods($addressId);

        $this->assertSame((int) config('shipment.methods.local_delivery.price'), $methods['local_delivery']['price']);
        $this->assertNull($methods['local_delivery']['price_breakdown']);
        $this->assertSame(0, $methods['in_person_pickup']['price']);
        $this->assertNull($methods['in_person_pickup']['price_breakdown']);
        $this->assertSame('TOMAN', $methods['in_person_pickup']['currency']);
    }

    // ── Checkout ───────────────────────────────────────────────────────────────

    #[Test]
    public function checkout_charges_exactly_the_listed_price_and_activation_never_recharges(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910, 1, 120000);
        $listed = $this->methods($addressId)['post_intercity_d3_parcel'];

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_intercity_d3_parcel',
            'address_id' => $addressId,
            // Never trusted.
            'shipping_cost' => 1,
            'price_breakdown' => ['total' => 1],
        ])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', 176500)
            ->assertJsonPath('tax_amount', 0)
            ->assertJsonPath('total_amount', 120000 + 176500)
            ->json('id');

        $order = Order::findOrFail($orderId);
        $this->assertSame($listed['price'], $order->shipping_cost);
        $this->assertSame($listed['price_breakdown'], $order->shipment_snapshot['price_breakdown']);
        $this->assertSame(1405, $order->shipment_snapshot['tariff_year']);
        $this->assertSame('non_neighbor', $order->shipment_snapshot['rate_zone']);
        $this->assertSame(910, $order->shipment_snapshot['weight_grams']);
        // Postal tax lives inside shipping_cost; tax_amount (product tax) is untouched.
        $this->assertSame(0, $order->tax_amount);

        $this->markOrderPaid($orderId);

        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $this->assertSame(176500, $shipment->shipping_cost);
        $this->assertSame($listed['price_breakdown'], $shipment->price_breakdown);
        $this->assertSame(176500 + 120000, $order->fresh()->total_amount);

        $this->getJson("/api/v1/shipments/{$shipment->public_code}")
            ->assertOk()
            ->assertJsonPath('price_breakdown.packaging', 50000);
    }

    #[Test]
    public function packaging_is_charged_once_whatever_the_quantity(): void
    {
        // 3 × 300 g = 900 g, one parcel.
        [, $addressId] = $this->customerWithCart(self::DEZFUL, 300, 3);

        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_parcel', 'address_id' => $addressId])
            ->assertCreated()->json('id');

        $breakdown = Order::findOrFail($orderId)->shipment_snapshot['price_breakdown'];
        $this->assertSame(50000, $breakdown['packaging']);
        $this->assertSame(100000, $breakdown['base_price']);
        $this->assertSame(100000 + 10000 + 50000, $breakdown['total']);
    }

    #[Test]
    public function local_delivery_and_pickup_orders_have_no_packaging_or_postal_tax(): void
    {
        $user = $this->actingAsCustomer();
        $this->createWeightedVariant('LD-PKG', 500, 30000);
        $this->addToCart($user->id, 'LD-PKG');

        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertCreated()->assertJsonPath('shipping_cost', 0)->assertJsonPath('total_amount', 30000)->json('id');
        $this->assertArrayNotHasKey('price_breakdown', Order::findOrFail($orderId)->shipment_snapshot);

        $this->addToCart($user->id, 'LD-PKG');
        $local = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $this->createAddress($user->id),
            'delivery_slot_id' => $this->createBookableSlot()->id,
        ])->assertCreated();
        $this->assertSame((int) config('shipment.methods.local_delivery.price'), $local->json('shipping_cost'));
    }

    #[Test]
    public function checkout_rejects_a_method_whose_route_is_not_supported(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);

        foreach (['post_intercity_d1_parcel', 'post_urban_3h_parcel', 'post_registered_parcel'] as $code) {
            $this->postJson('/api/v1/orders', ['shipment_method_code' => $code, 'address_id' => $addressId])
                ->assertStatus(422)
                ->assertJsonValidationErrors('shipment_method_code');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function checkout_never_falls_back_to_a_static_price_when_the_tariff_is_missing(): void
    {
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 910);
        PostalTariff1405::where('service_code', 'post_intercity_d3_parcel')->delete();

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_parcel', 'address_id' => $addressId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function an_enabled_envelope_service_cannot_be_bought_for_goods(): void
    {
        config(['shipping.post_1405.services.post_intercity_d3_envelope' => true]);
        [, $addressId] = $this->customerWithCart(self::AHVAZ, 300);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_envelope', 'address_id' => $addressId])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shipment_method_code');
    }

    #[Test]
    public function quote_and_checkout_agree_for_every_available_method(): void
    {
        config(['shipping.post_1405.coverage.intercity_d1.province_ids' => [11], 'shipping.post_1405.coverage.intercity_d2.city_ids' => [self::MASHHAD]]);

        foreach (['post_registered_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'] as $code) {
            [, $addressId] = $this->customerWithCart(self::MASHHAD, 3300);
            $listed = $this->methods($addressId)[$code];
            $this->assertTrue($listed['available'], $code);

            $this->postJson('/api/v1/orders', ['shipment_method_code' => $code, 'address_id' => $addressId])
                ->assertCreated()
                ->assertJsonPath('shipping_cost', $listed['price']);
        }
    }

    // ── History ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_historical_legacy_order_keeps_its_price_and_still_activates(): void
    {
        $user = $this->actingAsCustomer();
        $order = Order::createWithPublicCode([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 135000,
            'shipping_cost' => 85000,
            'tax_amount' => 0,
            'shipment_method_code' => 'post_standard',
            'shipping_address' => [],
            'shipment_snapshot' => [
                'method_code' => 'post_standard', 'method_title' => 'Standard Post', 'method_type' => 'postal',
                'shipping_cost' => 85000, 'requires_address' => true, 'address' => null, 'delivery_slot' => null, 'pickup_location' => null,
            ],
            'customer_snapshot' => [],
        ]);

        $this->markOrderPaid($order->id);

        $fresh = $order->fresh();
        $this->assertSame(85000, $fresh->shipping_cost);
        $this->assertSame(135000, $fresh->total_amount);
        $shipment = Shipment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('post_standard', $shipment->method_code);
        $this->assertSame(85000, $shipment->shipping_cost);
        $this->assertNull($shipment->price_breakdown);
        $this->assertSame(0, DB::table('orders')->where('id', $order->id)->value('tax_amount'));
    }
}
