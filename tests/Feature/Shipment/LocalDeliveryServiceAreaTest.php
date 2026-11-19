<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * The configured local-delivery service area (SHIPMENT_LOCAL_DELIVERY_*_IDS).
 *
 * Inside it the store delivers itself, so every postal method is withdrawn —
 * reported unavailable in the method list and rejected at checkout. Outside it, and
 * whenever no area is configured at all, nothing changes. Uses the production ids:
 * the shop is in Qom (province 19, city 599).
 */
class LocalDeliveryServiceAreaTest extends ShipmentTestCase
{
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
    public function postal_methods_are_withdrawn_for_an_address_inside_the_service_area(): void
    {
        [, $inside] = $this->setUpArea();

        $postal = $this->postal($this->methods($inside));

        $this->assertNotEmpty($postal);
        foreach ($postal as $method) {
            $this->assertFalse($method['available']);
            $this->assertNull($method['price']);
            $this->assertSame('inside_local_delivery_area', $method['unavailable_code']);
            $this->assertNotNull($method['unavailable_reason']);
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
    public function a_province_only_area_also_withdraws_postal(): void
    {
        $this->seedPost1405();
        Config::set('shipment.local_delivery.province_ids', [self::QOM_PROVINCE]);
        Config::set('shipment.local_delivery.city_ids', []);

        $user = $this->actingAsCustomer();
        $this->createVariantWithStock('AREA-PROV', 10000, 5);
        $this->addToCart($user->id, 'AREA-PROV');
        // A city that is not listed, but sits inside the configured province.
        $addressId = $this->postalAddress($user->id, self::QOM_OTHER_CITY);

        $this->assertSame('inside_local_delivery_area', $this->methods($addressId)['post_registered_parcel']['unavailable_code']);
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
    public function checkout_rejects_a_postal_method_for_an_address_inside_the_service_area(): void
    {
        [, $inside] = $this->setUpArea();

        foreach (['post_intercity_d3_parcel', 'post_registered_parcel'] as $code) {
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
