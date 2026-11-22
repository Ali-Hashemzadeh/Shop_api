<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment\Post1405;

use Illuminate\Support\Collection;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\PostalServiceUnavailableException;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalUnavailableReason as Reason;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

/**
 * Open-route mode (shipping.post_1405.allow_unverified_routes): a shop-side offer
 * policy that drops ONLY the manually maintained route allow-lists. Urban stays
 * same-city-only with a configured rate zone, intercity stays different-city-only,
 * and weight, carton, tariff and the missing-weight Normal Post fallback are
 * untouched. The method list and checkout share one pricing path.
 */
class Post1405OpenRouteModeTest extends ShipmentTestCase
{
    private const FALLBACK_PRICE = 130000;

    private const URBAN = ['post_urban_3h_parcel', 'post_urban_sameday_parcel', 'post_urban_nextday_parcel'];

    private const INTERCITY = ['post_intercity_sameday_parcel', 'post_intercity_d1_parcel', 'post_intercity_d2_parcel', 'post_intercity_d3_parcel'];

    /** city id => province id, mirroring the fixture */
    private const PROVINCE_OF = [599 => 19, 9001 => 19, 347 => 11, 382 => 13, 9003 => 13];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPost1405();
        config([
            // Every coverage allow-list empty — exactly the production situation.
            'shipping.post_1405.coverage' => [
                'urban_3h' => ['city_ids' => []],
                'urban_sameday' => ['city_ids' => []],
                'urban_nextday' => ['city_ids' => []],
                'intercity_sameday' => ['province_ids' => [], 'city_ids' => []],
                'intercity_d1' => ['province_ids' => [], 'city_ids' => []],
                'intercity_d2' => ['province_ids' => [], 'city_ids' => []],
            ],
            'shipping.post_1405.urban_zones' => ['metropolis' => [], 'province_center' => [self::QOM], 'other_city' => []],
            'shipping.post_1405.carton_size' => null,
            'shipping.missing_weight_fallback' => ['enabled' => true, 'price_toman' => self::FALLBACK_PRICE],
        ]);
    }

    private function openRoutes(bool $open = true): void
    {
        config(['shipping.post_1405.allow_unverified_routes' => $open]);
    }

    private function quote(string $service, int $grams, int $cityId, ?int $cartonSize = null): ShippingCostBreakdownDTO
    {
        return app(ShippingCostCalculatorInterface::class)->calculate(new ShippingCostRequestDTO(
            serviceCode: $service,
            weightGrams: $grams,
            originProvinceId: self::QOM_PROVINCE,
            originCityId: self::QOM,
            destinationProvinceId: self::PROVINCE_OF[$cityId],
            destinationCityId: $cityId,
            cartonSize: $cartonSize,
        ));
    }

    private function assertUnavailable(Reason $reason, callable $quote): void
    {
        try {
            $quote();
            $this->fail("Expected the method to be unavailable ({$reason->value}).");
        } catch (PostalServiceUnavailableException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function methods(int $addressId): Collection
    {
        return collect($this->getJson("/api/v1/shipment/methods?address_id={$addressId}")->assertOk()->json('data'))->keyBy('code');
    }

    /**
     * @param  array<string, array{0: ?int, 1: int}>  $lines  sku => [unit grams, qty]
     * @return array{0: int, 1: int} user id, address id
     */
    private function customerWithCart(int $cityId, array $lines, int $price = 50000): array
    {
        $user = $this->actingAsCustomer();

        foreach ($lines as $prefix => [$grams, $qty]) {
            // Unique per call: some tests build several carts in one run.
            $sku = $prefix.'-'.uniqid();
            $this->createWeightedVariant($sku, $grams, $price, 50);
            $this->addToCart($user->id, $sku, $qty);
        }

        return [$user->id, $this->postalAddress($user->id, $cityId)];
    }

    // ── 1–3. The allow-list is the only thing open-route mode removes ───────────

    #[Test]
    public function strict_mode_keeps_d2_unavailable_with_an_empty_allowlist(): void
    {
        $this->openRoutes(false);

        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d2_parcel', 1000, self::AHVAZ));
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d1_parcel', 1000, self::AHVAZ));
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_urban_nextday_parcel', 1000, self::QOM));
    }

    #[Test]
    public function open_route_mode_prices_d2_with_an_empty_allowlist(): void
    {
        $this->openRoutes();

        $q = $this->quote('post_intercity_d2_parcel', 1000, self::AHVAZ);

        // Non-neighbour 110,000 + 15 % metropolitan (Ahvaz) + 10 % tax + 50,000 packaging.
        $this->assertSame('non_neighbor', $q->rateZone);
        $this->assertSame(110000, $q->basePrice);
        $this->assertSame(16500, $q->deliverySurcharge);
        $this->assertSame(12650, $q->postalTax);
        $this->assertSame(50000, $q->packaging);
        $this->assertSame(189150, $q->total());
    }

    #[Test]
    public function open_route_mode_prices_d1_with_an_empty_allowlist(): void
    {
        $this->openRoutes();

        $q = $this->quote('post_intercity_d1_parcel', 1000, self::AHVAZ);

        $this->assertSame(153000, $q->basePrice);
        $this->assertSame(22950, $q->deliverySurcharge);
        $this->assertSame(17595, $q->postalTax);
        $this->assertSame(153000 + 22950 + 17595 + 50000, $q->total());
    }

    // ── 4–7. Urban vs intercity geography is never relaxed ───────────────────────

    #[Test]
    public function urban_methods_never_serve_a_different_city(): void
    {
        $this->openRoutes();

        foreach (self::URBAN as $code) {
            $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote($code, 1000, self::AHVAZ));
            // Not even another city in the origin province.
            $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote($code, 1000, self::QOM_OTHER_CITY));
        }
    }

    #[Test]
    public function urban_methods_are_priced_for_same_city_qom_in_the_configured_zone(): void
    {
        $this->openRoutes();

        $q = $this->quote('post_urban_nextday_parcel', 1000, self::QOM);

        // Province-centre rate 73,000 + 15 % metropolitan (Qom 599) + tax + packaging.
        $this->assertSame('province_center', $q->rateZone);
        $this->assertSame(73000, $q->basePrice);
        $this->assertSame(10950, $q->deliverySurcharge);
        $this->assertSame(8395, $q->postalTax);
        $this->assertSame(142345, $q->total());

        foreach (self::URBAN as $code) {
            $this->assertSame('province_center', $this->quote($code, 1000, self::QOM)->rateZone, $code);
        }
    }

    #[Test]
    public function intercity_methods_never_serve_same_city_qom(): void
    {
        $this->openRoutes();
        config(['shipping.post_1405.carton_size' => 6]);

        foreach (self::INTERCITY as $code) {
            $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote($code, 1000, self::QOM));
        }
    }

    #[Test]
    public function another_city_in_qom_province_is_intercity_at_the_same_province_rate(): void
    {
        $this->openRoutes();

        $q = $this->quote('post_intercity_d2_parcel', 1000, self::QOM_OTHER_CITY);

        $this->assertSame('same_province', $q->rateZone);
        $this->assertSame(90000, $q->basePrice);
        $this->assertSame(0, $q->deliverySurcharge);
        $this->assertSame(149000, $q->total());
        $this->assertSame('same_province', $this->quote('post_intercity_d1_parcel', 1000, self::QOM_OTHER_CITY)->rateZone);
        $this->assertSame('same_province', $this->quote('post_intercity_d3_parcel', 1000, self::QOM_OTHER_CITY)->rateZone);
    }

    // ── 8–9. Intercity same-day keeps its carton + 10 kg rules ───────────────────

    #[Test]
    public function same_day_intercity_with_an_unknown_carton_is_unavailable_even_in_open_route_mode(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['SD' => [1000, 1]]);

        $this->assertUnavailable(Reason::CartonSizeUnknown, fn () => $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ));

        $sameDay = $this->methods($addressId)['post_intercity_sameday_parcel'];
        $this->assertFalse($sameDay['available']);
        $this->assertNull($sameDay['price']);
        $this->assertSame('carton_size_unknown', $sameDay['unavailable_code']);
        $this->assertSame('ابعاد بسته برای این سرویس مشخص نیست.', $sameDay['unavailable_reason']);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_sameday_parcel', 'address_id' => $addressId])
            ->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        $this->assertDatabaseCount('orders', 0);

        // A known carton is evaluated against the real rule (max size 6).
        $this->assertUnavailable(Reason::CartonTooLarge, fn () => $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ, 7));
        $this->assertSame(287000, $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ, 6)->basePrice);
    }

    #[Test]
    public function same_day_intercity_over_10kg_is_unavailable(): void
    {
        $this->openRoutes();

        $this->assertSame(287000 + 9 * 21500, $this->quote('post_intercity_sameday_parcel', 10000, self::AHVAZ, 6)->postalServiceCharge());
        $this->assertUnavailable(Reason::WeightNotSupported, fn () => $this->quote('post_intercity_sameday_parcel', 10001, self::AHVAZ, 6));
    }

    // ── 10–11. Registered parcel's published brackets start above 2 kg ───────────

    #[Test]
    public function registered_parcel_below_2kg_is_unavailable_and_never_gets_a_document_rate(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['RP-LIGHT' => [500, 2]]);

        foreach ([500, 1000, 2000] as $grams) {
            $this->assertUnavailable(Reason::TariffMissing, fn () => $this->quote('post_registered_parcel', $grams, self::AHVAZ));
        }

        $parcel = $this->methods($addressId)['post_registered_parcel'];
        $this->assertFalse($parcel['available']);
        $this->assertNull($parcel['price']);
        $this->assertSame('tariff_missing', $parcel['unavailable_code']);
    }

    #[Test]
    public function registered_parcel_above_2kg_is_priced_from_its_published_bracket(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['RP-HEAVY' => [2500, 1]]);

        $parcel = $this->methods($addressId)['post_registered_parcel'];

        $this->assertTrue($parcel['available']);
        $this->assertSame(44900, $parcel['price_breakdown']['base_price']);
        $this->assertSame(7700, $parcel['price_breakdown']['electronic_service_charge']);
        $this->assertSame($parcel['price_breakdown']['total'], $parcel['price']);
    }

    // ── 12–13. Missing-weight fallback and envelope toggles are untouched ────────

    #[Test]
    public function the_missing_weight_fallback_still_wins_in_open_route_mode(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['MW-OK' => [800, 1], 'MW-NONE' => [null, 1]]);

        $methods = $this->methods($addressId);
        $postal = $methods->where('type', 'postal');

        $this->assertSame(['post_standard'], $postal->where('available', true)->keys()->values()->all());
        $this->assertSame(self::FALLBACK_PRICE, $methods['post_standard']['price']);
        foreach ([...self::INTERCITY, 'post_registered_parcel'] as $code) {
            $this->assertSame('weight_missing', $methods[$code]['unavailable_code'], $code);
            $this->assertNull($methods[$code]['price']);
        }

        // A hand-edited dynamic code is refused; the fallback is charged flat.
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d2_parcel', 'address_id' => $addressId])
            ->assertStatus(422)->assertJsonValidationErrors('shipment_method_code');
        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_standard', 'address_id' => $addressId])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', self::FALLBACK_PRICE);
    }

    #[Test]
    public function the_fallback_stays_withdrawn_inside_the_local_delivery_area(): void
    {
        $this->openRoutes();
        config(['shipment.local_delivery.city_ids' => [self::QOM]]);
        [, $addressId] = $this->customerWithCart(self::QOM, ['MW-QOM' => [null, 1]]);

        $methods = $this->methods($addressId);

        $this->assertSame('inside_local_delivery_area', $methods['post_standard']['unavailable_code']);
        $this->assertSame([], $methods->where('type', 'postal')->where('available', true)->all());
        $this->assertTrue($methods['local_delivery']['available']);
    }

    #[Test]
    public function disabled_envelope_services_are_not_listed_in_open_route_mode(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['ENV' => [300, 1]]);

        $codes = $this->methods($addressId)->keys()->all();

        foreach (Post1405Catalog::services() as $code => $service) {
            $enabled = (bool) config("shipping.post_1405.services.{$code}");
            $this->assertSame($enabled, in_array($code, $codes, true), $code);
            if ($service->packageKind === 'envelope') {
                $this->assertFalse($enabled, $code);
            }
        }
    }

    // ── 14–15. List and checkout agree; tax and packaging exactly once ───────────

    #[Test]
    public function quote_and_checkout_agree_for_every_available_method_with_no_allowlist(): void
    {
        $this->openRoutes();
        config(['shipping.post_1405.carton_size' => 6]);

        $cases = [
            self::MASHHAD => ['post_registered_parcel', ...self::INTERCITY],
            self::QOM => self::URBAN,
        ];

        foreach ($cases as $city => $codes) {
            foreach ($codes as $code) {
                [, $addressId] = $this->customerWithCart($city, ['AG' => [3300, 1]]);
                $listed = $this->methods($addressId)[$code];
                $this->assertTrue($listed['available'], "{$code} → {$city}");

                $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => $code, 'address_id' => $addressId])
                    ->assertCreated()
                    ->assertJsonPath('shipping_cost', $listed['price'])
                    ->json('id');

                $this->assertSame($listed['price_breakdown'], Order::findOrFail($orderId)->shipment_snapshot['price_breakdown']);
            }
        }
    }

    #[Test]
    public function postal_tax_and_packaging_are_charged_exactly_once(): void
    {
        $this->openRoutes();
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['TX' => [700, 3]]);

        foreach ($this->methods($addressId)->where('type', 'postal')->where('available', true) as $code => $method) {
            $b = $method['price_breakdown'];
            $taxable = $b['base_price'] + $b['extra_weight'] + $b['delivery_surcharge'] + $b['large_province_surcharge']
                + $b['island_surcharge'] + $b['fragile_surcharge'] + $b['electronic_service_charge'] + $b['compensation'];

            $this->assertSame(intdiv($taxable * 10, 100), $b['postal_tax'], $code);
            $this->assertSame(50000, $b['packaging'], $code);
            $this->assertSame($taxable + $b['postal_tax'] + $b['packaging'], $b['total'], $code);
            $this->assertSame($b['total'], $method['price'], $code);
        }

        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d2_parcel', 'address_id' => $addressId])
            ->assertCreated()->json('id');
        $this->markOrderPaid($orderId);

        $order = Order::findOrFail($orderId);
        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $this->assertSame($order->shipping_cost, $shipment->shipping_cost);
        $this->assertSame(0, $order->tax_amount);
    }

    // ── 16–17. Local delivery / pickup and history are untouched ─────────────────

    #[Test]
    public function local_delivery_and_pickup_keep_their_static_prices(): void
    {
        $this->openRoutes();
        config(['shipment.local_delivery.city_ids' => [self::QOM]]);
        [, $addressId] = $this->customerWithCart(self::QOM, ['LD' => [1000, 1]]);

        $methods = $this->methods($addressId);

        $this->assertTrue($methods['local_delivery']['available']);
        $this->assertSame((int) config('shipment.methods.local_delivery.price'), $methods['local_delivery']['price']);
        $this->assertNull($methods['local_delivery']['price_breakdown']);
        $this->assertTrue($methods['in_person_pickup']['available']);
        $this->assertSame((int) config('shipment.methods.in_person_pickup.price'), $methods['in_person_pickup']['price']);

        $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertCreated()
            ->assertJsonPath('shipping_cost', (int) config('shipment.methods.in_person_pickup.price'));
    }

    #[Test]
    public function an_existing_order_snapshot_is_never_recalculated_after_the_policy_changes(): void
    {
        $this->openRoutes(false);
        [, $addressId] = $this->customerWithCart(self::AHVAZ, ['HIST' => [910, 1]]);

        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'post_intercity_d3_parcel', 'address_id' => $addressId])
            ->assertCreated()->json('id');
        $before = Order::findOrFail($orderId);

        // Flip the policy and the surcharge inputs after checkout.
        $this->openRoutes();
        config(['shipping.post_1405.packaging_price_toman' => 999999, 'shipping.surcharges.metropolitan_enabled' => false]);

        $this->markOrderPaid($orderId);

        $after = $before->fresh();
        $this->assertSame($before->shipping_cost, $after->shipping_cost);
        $this->assertSame($before->total_amount, $after->total_amount);
        $this->assertSame($before->shipment_snapshot, $after->shipment_snapshot);

        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();
        $this->assertSame($before->shipping_cost, $shipment->shipping_cost);
        $this->assertSame($before->shipment_snapshot['price_breakdown'], $shipment->price_breakdown);
    }
}
