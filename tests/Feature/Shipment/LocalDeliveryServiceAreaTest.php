<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The configured local-delivery service area (SHIPMENT_LOCAL_DELIVERY_*_IDS).
 *
 * Inside it the store delivers itself, so both postal methods are withdrawn —
 * hidden from the method list and rejected at checkout. Outside it, and whenever
 * no area is configured at all, nothing changes.
 */
class LocalDeliveryServiceAreaTest extends ShipmentTestCase
{
    /** @return array{0:int,1:int,2:int} user id, in-area address id, outside address id */
    private function setUpArea(): array
    {
        [$provinceId, $cityId] = $this->createProvinceCity();
        [$otherProvinceId, $otherCityId] = $this->createProvinceCity();

        Config::set('shipment.local_delivery.province_ids', []);
        Config::set('shipment.local_delivery.city_ids', [$cityId]);

        $user = $this->actingAsCustomer();

        return [
            $user->id,
            $this->createAddress($user->id, $provinceId, $cityId),
            $this->createAddress($user->id, $otherProvinceId, $otherCityId),
        ];
    }

    private function methods(?int $addressId = null): Collection
    {
        $uri = '/api/v1/shipment/methods'.($addressId !== null ? "?address_id={$addressId}" : '');

        return collect($this->getJson($uri)->assertOk()->json('data'))->keyBy('code');
    }

    // ── Method listing ─────────────────────────────────────────────────────────

    /** @test */
    public function postal_methods_are_withdrawn_for_an_address_inside_the_service_area(): void
    {
        [, $inside] = $this->setUpArea();

        $methods = $this->methods($inside);

        $this->assertFalse($methods['post_standard']['available']);
        $this->assertFalse($methods['post_express']['available']);
        $this->assertNotNull($methods['post_standard']['unavailable_reason']);
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

        $this->assertTrue($methods['post_standard']['available']);
        $this->assertTrue($methods['post_express']['available']);
        $this->assertFalse($methods['local_delivery']['available']);
    }

    /** @test */
    public function a_province_only_area_also_withdraws_postal(): void
    {
        [$provinceId] = $this->createProvinceCity();
        [, $otherCityId] = $this->createProvinceCity();

        Config::set('shipment.local_delivery.province_ids', [$provinceId]);
        Config::set('shipment.local_delivery.city_ids', []);

        $user = $this->actingAsCustomer();
        // A city that is not listed, but sits inside the configured province.
        $addressId = $this->createAddress($user->id, $provinceId, $otherCityId);

        $this->assertFalse($this->methods($addressId)['post_standard']['available']);
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
        [$provinceId, $cityId] = $this->createProvinceCity();
        Config::set('shipment.local_delivery.province_ids', []);
        Config::set('shipment.local_delivery.city_ids', []);

        $user = $this->actingAsCustomer();
        $addressId = $this->createAddress($user->id, $provinceId, $cityId);

        $methods = $this->methods($addressId);

        $this->assertTrue($methods['post_standard']['available']);
        $this->assertTrue($methods['post_express']['available']);
        $this->assertTrue($methods['local_delivery']['available']);
    }

    /** @test */
    public function postal_is_available_when_no_address_is_selected_yet(): void
    {
        $this->setUpArea();

        $methods = $this->methods();

        $this->assertTrue($methods['post_standard']['available']);
    }

    // ── Checkout enforcement ───────────────────────────────────────────────────

    /** @test */
    public function checkout_rejects_a_postal_method_for_an_address_inside_the_service_area(): void
    {
        [$userId, $inside] = $this->setUpArea();
        $this->createVariantWithStock('AREA-SKU-1');
        $this->addToCart($userId, 'AREA-SKU-1');

        foreach (['post_standard', 'post_express'] as $code) {
            $this->postJson('/api/v1/orders', [
                'shipment_method_code' => $code,
                'address_id' => $inside,
            ])->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        }

        $this->assertDatabaseCount('orders', 0);
    }

    /** @test */
    public function checkout_accepts_a_postal_method_outside_the_service_area(): void
    {
        [$userId, , $outside] = $this->setUpArea();
        $this->createVariantWithStock('AREA-SKU-2');
        $this->addToCart($userId, 'AREA-SKU-2');

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'post_standard',
            'address_id' => $outside,
        ])->assertCreated();
    }

    /** @test */
    public function checkout_accepts_in_store_pickup_inside_the_service_area(): void
    {
        [$userId] = $this->setUpArea();
        $this->createVariantWithStock('AREA-SKU-3');
        $this->addToCart($userId, 'AREA-SKU-3');

        $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'in_person_pickup',
        ])->assertCreated();
    }
}
