<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The configured local-delivery service area (SHIPMENT_LOCAL_DELIVERY_*_IDS).
 *
 * Local delivery and the Iran Post 1405 methods are evaluated INDEPENDENTLY: the
 * area decides only whether local delivery is offered. Inside Qom city the urban
 * postal services may be offered beside local delivery (intercity never — that is
 * the 1405 engine's same-city rule); another city in Qom province is an intercity
 * same_province destination even under a province-level area. Only the Normal Post
 * missing-weight fallback keeps its "not inside the area" rule (see
 * MissingWeightNormalPostFallbackTest). Production ids: Qom = province 19, city 599.
 */
class LocalDeliveryServiceAreaTest extends ShipmentTestCase
{
    private const URBAN = ['post_urban_3h_parcel', 'post_urban_sameday_parcel', 'post_urban_nextday_parcel'];

    private const INTERCITY = ['post_intercity_sameday_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'];

    /** @return array{0:int,1:int,2:int} user id, in-area (Qom) address id, outside (Ahvaz) address id */
    private function setUpArea(): array
    {
        $this->seedPost1405();

        Config::set('shipment.local_delivery.province_ids', []);
        Config::set('shipment.local_delivery.city_ids', [self::QOM]);

        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('AREA-CART', 10000, 50);
        $this->addToCart($user->id, 'AREA-CART');

        return [
            $user->id,
            $this->postalAddress($user->id, self::QOM),
            $this->postalAddress($user->id, self::AHVAZ),
        ];
    }

    /** Production route policy: open routes, Qom rated as an urban province centre. */
    private function productionRoutePolicy(): void
    {
        Config::set('shipping.post_1405.allow_unverified_routes', true);
        Config::set('shipping.post_1405.urban_zones.province_center', [self::QOM]);
    }

    private function methods(?int $addressId = null): Collection
    {
        $uri = '/api/v1/shipment/methods'.($addressId !== null ? "?address_id={$addressId}" : '');

        return collect($this->getJson($uri)->assertOk()->json('data'))->keyBy('code');
    }

    private function postal(Collection $methods): Collection
    {
        return $methods->where('type', 'postal');
    }

    // ── Method listing ─────────────────────────────────────────────────────────

    /** @test */
    public function urban_post_is_offered_beside_local_delivery_inside_qom_city_and_intercity_is_not(): void
    {
        [, $inside] = $this->setUpArea();
        $this->productionRoutePolicy();

        $methods = $this->methods($inside);

        foreach (self::URBAN as $code) {
            $this->assertTrue($methods[$code]['available'], $code);
            $this->assertIsInt($methods[$code]['price']);
            $this->assertSame('province_center', $methods[$code]['rate_zone']);
        }

        foreach (self::INTERCITY as $code) {
            $this->assertFalse($methods[$code]['available'], $code);
            $this->assertNull($methods[$code]['price']);
            $this->assertSame('route_not_supported', $methods[$code]['unavailable_code']);
        }

        // The area itself no longer withdraws any 1405 method.
        $this->assertSame([], $this->postal($methods)->where('unavailable_code', 'inside_local_delivery_area')->all());
        $this->assertTrue($methods['local_delivery']['available']);
        $this->assertTrue($methods['in_person_pickup']['available']);
    }

    /** @test */
    public function strict_route_policy_still_keeps_urban_post_off_in_qom_city(): void
    {
        [, $inside] = $this->setUpArea();
        Config::set('shipping.post_1405.urban_zones.province_center', [self::QOM]);

        $methods = $this->methods($inside);

        foreach (self::URBAN as $code) {
            $this->assertFalse($methods[$code]['available'], $code);
            $this->assertSame('route_not_supported', $methods[$code]['unavailable_code']);
        }
        $this->assertTrue($methods['local_delivery']['available']);
    }

    /** @test */
    public function urban_post_needs_a_configured_rate_zone_even_in_open_route_mode(): void
    {
        [, $inside] = $this->setUpArea();
        Config::set('shipping.post_1405.allow_unverified_routes', true);

        foreach (self::URBAN as $code) {
            $this->assertSame('zone_unclassified', $this->methods($inside)[$code]['unavailable_code'], $code);
        }
    }

    /** @test */
    public function local_delivery_and_pickup_stay_available_inside_the_service_area(): void
    {
        [, $inside] = $this->setUpArea();

        $methods = $this->methods($inside);

        $this->assertTrue($methods['local_delivery']['available']);
        $this->assertTrue($methods['in_person_pickup']['available']);
    }

    /** @test */
    public function postal_stays_available_outside_the_service_area_where_local_delivery_is_not(): void
    {
        [, , $outside] = $this->setUpArea();

        $methods = $this->methods($outside);

        $this->assertTrue($methods['post_intercity_d3_parcel']['available']);
        $this->assertFalse($methods['local_delivery']['available']);
    }

    /** @test */
    public function another_city_in_qom_province_gets_intercity_post_even_under_a_province_level_area(): void
    {
        $this->seedPost1405();
        $this->productionRoutePolicy();
        // The production area: province 19 + city 599.
        Config::set('shipment.local_delivery.province_ids', [self::QOM_PROVINCE]);
        Config::set('shipment.local_delivery.city_ids', [self::QOM]);

        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('AREA-PROV', 10000, 5);
        $this->addToCart($user->id, 'AREA-PROV');
        $addressId = $this->postalAddress($user->id, self::QOM_OTHER_CITY);

        $methods = $this->methods($addressId);

        // Intercity, priced in the same_province zone; local delivery still offered.
        foreach (['post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'] as $code) {
            $this->assertTrue($methods[$code]['available'], $code);
            $this->assertSame('same_province', $methods[$code]['rate_zone'], $code);
        }
        // Urban never extends to the rest of the province.
        foreach (self::URBAN as $code) {
            $this->assertFalse($methods[$code]['available'], $code);
            $this->assertSame('route_not_supported', $methods[$code]['unavailable_code']);
        }
        $this->assertTrue($methods['local_delivery']['available']);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d2_parcel', 'address_id' => $addressId])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', $methods['post_intercity_d2_parcel']['price']);
    }

    /**
     * The regression that matters: isEligible() is permissive with no area
     * configured, so a naive "postal off wherever local delivery is on" would
     * disable post for every customer in the country.
     *
     * @test
     */
    public function postal_is_untouched_when_no_service_area_is_configured(): void
    {
        $this->seedPost1405();
        Config::set('shipment.local_delivery.province_ids', []);
        Config::set('shipment.local_delivery.city_ids', []);

        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('AREA-NONE', 10000, 5);
        $this->addToCart($user->id, 'AREA-NONE');
        $addressId = $this->postalAddress($user->id, self::AHVAZ);

        $methods = $this->methods($addressId);

        $this->assertTrue($methods['post_intercity_d3_parcel']['available']);
        $this->assertTrue($methods['local_delivery']['available']);
    }

    /** @test */
    public function only_pickup_is_returned_when_no_address_is_selected_yet(): void
    {
        $this->setUpArea();

        $methods = $this->methods();

        $this->assertSame(['in_person_pickup'], $methods->keys()->all());
    }

    // ── Checkout enforcement ───────────────────────────────────────────────────

    /** @test */
    public function checkout_rejects_an_intercity_method_for_qom_city_and_accepts_an_urban_one(): void
    {
        [, $inside] = $this->setUpArea();
        $this->productionRoutePolicy();
        $listed = $this->methods($inside);

        // A hand-edited intercity code cannot bypass the same-city rule.
        foreach (self::INTERCITY as $code) {
            $this->postJson('/api/v1/orders', [
                'shipment_method_code' => $code,
                'address_id' => $inside,
            ])->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        }
        $this->assertDatabaseCount('orders', 0);

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_urban_nextday_parcel',
            'address_id' => $inside,
        ])->assertCreated()->assertJsonPath('shipping_cost', $listed['post_urban_nextday_parcel']['price']);
    }

    /** @test */
    public function checkout_accepts_a_postal_method_outside_the_service_area(): void
    {
        [, , $outside] = $this->setUpArea();

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_intercity_d3_parcel',
            'address_id' => $outside,
        ])->assertCreated();
    }

    /** @test */
    public function checkout_accepts_in_store_pickup_inside_the_service_area(): void
    {
        $this->setUpArea();

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'in_person_pickup',
        ])->assertCreated();
    }
}
