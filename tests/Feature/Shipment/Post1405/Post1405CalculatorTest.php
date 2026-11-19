<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment\Post1405;

use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\PostalServiceUnavailableException;
use Modules\Shipment\Domain\Models\PostalTariff1405;
use Modules\Shipment\Domain\Models\ShippingParameter;
use Modules\Shipment\Domain\Postal\PostalUnavailableReason as Reason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

/**
 * The Iran Post 1405 engine in isolation: exact tariff boundaries, geographic
 * surcharges with the production ids, island/fragile/ancillary fees, the 10 % postal
 * tax, the flat packaging fee, route coverage, and fail-closed behaviour.
 */
class Post1405CalculatorTest extends ShipmentTestCase
{
    /** city id => province id, mirroring the fixture */
    private const PROVINCE_OF = [
        599 => 19, 9001 => 19, 219 => 8, 9002 => 8, 162 => 5, 347 => 11, 115 => 4, 530 => 17,
        9 => 1, 382 => 13, 9003 => 13, 703 => 22, 9004 => 28, 9005 => 24,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPost1405();
    }

    private function quote(string $service, int $grams, ?int $cityId = self::DEZFUL, array $overrides = []): ShippingCostBreakdownDTO
    {
        return app(ShippingCostCalculatorInterface::class)->calculate(new ShippingCostRequestDTO(...array_merge([
            'serviceCode' => $service,
            'weightGrams' => $grams,
            'originProvinceId' => self::QOM_PROVINCE,
            'originCityId' => self::QOM,
            'destinationProvinceId' => $cityId !== null ? self::PROVINCE_OF[$cityId] : null,
            'destinationCityId' => $cityId,
        ], $overrides)));
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

    private function enable(string ...$codes): void
    {
        foreach ($codes as $code) {
            config(["shipping.post_1405.services.{$code}" => true]);
        }
    }

    // ── Base tariffs & weight boundaries ───────────────────────────────────────

    /** @return array<string, array{int, int}> */
    public static function d3NonNeighbourParcel(): array
    {
        return [
            '400 g' => [400, 100000], '500 g' => [500, 100000], '501 g' => [501, 100000],
            '999 g' => [999, 100000], '1000 g' => [1000, 100000], '1001 g' => [1001, 117500],
            '1590 g' => [1590, 117500], '2000 g' => [2000, 117500], '2001 g' => [2001, 135000],
            '3000 g' => [3000, 135000], '3001 g' => [3001, 152500], '5000 g' => [5000, 170000],
            '5001 g' => [5001, 187500],
        ];
    }

    #[Test]
    #[DataProvider('d3NonNeighbourParcel')]
    public function d3_parcel_non_neighbour_base_postage_follows_started_kilograms(int $grams, int $expected): void
    {
        // Dezful: Khuzestan (non-neighbour of Qom), not metropolitan, not large.
        $quote = $this->quote('post_intercity_d3_parcel', $grams);

        $this->assertSame('non_neighbor', $quote->rateZone);
        $this->assertSame($expected, $quote->postalServiceCharge());
        $this->assertSame(100000, $quote->basePrice);
    }

    #[Test]
    public function a_400_gram_parcel_never_receives_the_d3_envelope_rate(): void
    {
        $this->enable('post_intercity_d3_envelope');

        $this->assertSame(100000, $this->quote('post_intercity_d3_parcel', 400)->basePrice);
        // The envelope service exists but is a document service: goods never use it.
        $this->assertUnavailable(Reason::ContentNotEligible, fn () => $this->quote('post_intercity_d3_envelope', 400));
    }

    /** @return array<string, array{int, ?int}> */
    public static function registeredParcelNonNeighbour(): array
    {
        return [
            '2001 g' => [2001, 44900], '2500 g' => [2500, 44900], '3000 g' => [3000, 44900],
            '3001 g' => [3001, 52500], '5000 g' => [5000, 52500], '5001 g' => [5001, 62500],
            '6000 g' => [6000, 62500], '6001 g' => [6001, 72500],
        ];
    }

    #[Test]
    #[DataProvider('registeredParcelNonNeighbour')]
    public function registered_parcel_non_neighbour_brackets(int $grams, int $expected): void
    {
        $this->assertSame($expected, $this->quote('post_registered_parcel', $grams)->postalServiceCharge());
    }

    #[Test]
    public function lightweight_goods_are_never_priced_as_documents_or_given_a_fabricated_parcel_rate(): void
    {
        $this->enable('post_registered_document', 'post_economy_unregistered');

        foreach ([400, 500, 999, 1000, 2000] as $grams) {
            // Registered parcel's first bracket starts above 2 kg — no envelope fallback.
            $this->assertUnavailable(Reason::TariffMissing, fn () => $this->quote('post_registered_parcel', $grams));
        }

        $this->assertUnavailable(Reason::ContentNotEligible, fn () => $this->quote('post_registered_document', 400));
        $this->assertUnavailable(Reason::ContentNotEligible, fn () => $this->quote('post_economy_unregistered', 400));
    }

    #[Test]
    public function the_flagged_same_province_rate_is_applied_verbatim(): void
    {
        // Jafarieh: another city in Qom province → same_province.
        $this->assertSame('same_province', $this->quote('post_registered_parcel', 2500, self::QOM_OTHER_CITY)->rateZone);
        $this->assertSame(32000, $this->quote('post_registered_parcel', 3000, self::QOM_OTHER_CITY)->basePrice);
        $this->assertSame(27000, $this->quote('post_registered_parcel', 3001, self::QOM_OTHER_CITY)->basePrice);
        $this->assertSame(27000 + 7500, $this->quote('post_registered_parcel', 5001, self::QOM_OTHER_CITY)->postalServiceCharge());
    }

    #[Test]
    public function registered_packet_boundaries_when_explicitly_enabled(): void
    {
        $this->enable('post_registered_packet');

        $this->assertUnavailable(Reason::TariffMissing, fn () => $this->quote('post_registered_packet', 500));
        $this->assertSame(32000, $this->quote('post_registered_packet', 501)->basePrice);
        $this->assertSame(32000, $this->quote('post_registered_packet', 1000)->basePrice);
        $this->assertSame(41200, $this->quote('post_registered_packet', 1001)->basePrice);
        $this->assertSame(41200, $this->quote('post_registered_packet', 2000)->basePrice);
        $this->assertUnavailable(Reason::WeightNotSupported, fn () => $this->quote('post_registered_packet', 2001));
    }

    #[Test]
    public function neighbour_zone_comes_from_identity_adjacency(): void
    {
        // Arak (Markazi, 28) and Isfahan (4) neighbour Qom; Gorgan (24) does not.
        $this->assertSame('neighbor', $this->quote('post_intercity_d3_parcel', 1000, self::ARAK)->rateZone);
        $this->assertSame(90000, $this->quote('post_intercity_d3_parcel', 1000, self::ARAK)->basePrice);
        $this->assertSame(90000 + 15500, $this->quote('post_intercity_d3_parcel', 1001, self::ARAK)->postalServiceCharge());
        $this->assertSame('neighbor', $this->quote('post_intercity_d3_parcel', 1000, self::ISFAHAN)->rateZone);
        $this->assertSame('non_neighbor', $this->quote('post_intercity_d3_parcel', 1000, self::GORGAN)->rateZone);
    }

    // ── Geographic surcharges ──────────────────────────────────────────────────

    #[Test]
    public function tehran_province_pays_20_percent_and_nothing_else(): void
    {
        foreach ([self::TEHRAN, self::TEHRAN_OTHER_CITY] as $city) {
            $q = $this->quote('post_intercity_d3_parcel', 1000, $city); // neighbour: 90,000
            $this->assertSame(18000, $q->deliverySurcharge);
            $this->assertSame('tehran_alborz', $q->deliverySurchargeRule);
            $this->assertSame(0, $q->largeProvinceSurcharge);
        }
    }

    #[Test]
    public function alborz_province_pays_20_percent(): void
    {
        $q = $this->quote('post_intercity_d3_parcel', 1000, self::KARAJ); // non-neighbour: 100,000

        $this->assertSame(20000, $q->deliverySurcharge);
        $this->assertSame('tehran_alborz', $q->deliverySurchargeRule);
    }

    /** @return array<string, array{int, int, int, int}> city, expected base, metro, large */
    public static function metropolitanCities(): array
    {
        return [
            'Mashhad 347' => [347, 100000, 15000, 5000],
            'Isfahan 115' => [115, 90000, 13500, 4500],
            'Shiraz 530' => [530, 100000, 15000, 5000],
            'Tabriz 9' => [9, 100000, 15000, 0],
            'Ahvaz 382' => [382, 100000, 15000, 0],
            'Kermanshah 703' => [703, 100000, 15000, 0],
        ];
    }

    #[Test]
    #[DataProvider('metropolitanCities')]
    public function metropolitan_cities_pay_15_percent_and_large_provinces_add_5(int $city, int $base, int $metro, int $large): void
    {
        $q = $this->quote('post_intercity_d3_parcel', 1000, $city);

        $this->assertSame($base, $q->basePrice);
        $this->assertSame($metro, $q->deliverySurcharge);
        $this->assertSame('metropolitan', $q->deliverySurchargeRule);
        $this->assertSame($large, $q->largeProvinceSurcharge);
    }

    #[Test]
    public function qom_city_is_metropolitan_but_not_a_large_province(): void
    {
        // Registered parcel (any route) to Qom city itself, with no local area configured.
        $q = $this->quote('post_registered_parcel', 2500, self::QOM); // same_province 32,000

        $this->assertSame(4800, $q->deliverySurcharge);
        $this->assertSame(0, $q->largeProvinceSurcharge);
    }

    #[Test]
    public function an_unlisted_city_pays_no_metropolitan_surcharge(): void
    {
        $q = $this->quote('post_intercity_d3_parcel', 1000, self::DEZFUL);

        $this->assertSame(0, $q->deliverySurcharge);
        $this->assertNull($q->deliverySurchargeRule);
        $this->assertSame(0, $q->largeProvinceSurcharge);
    }

    #[Test]
    public function the_20_and_15_percent_rules_never_stack(): void
    {
        // Even if Tehran city were added to the metropolitan list, only 20 % applies.
        config(['shipping.surcharges.metropolitan_city_ids' => [self::TEHRAN, self::AHVAZ]]);

        $q = $this->quote('post_intercity_d3_parcel', 1000, self::TEHRAN);

        $this->assertSame(18000, $q->deliverySurcharge);
        $this->assertSame('tehran_alborz', $q->deliverySurchargeRule);
    }

    #[Test]
    public function metropolitan_and_large_province_switches_turn_the_surcharges_off(): void
    {
        config(['shipping.surcharges.metropolitan_enabled' => false, 'shipping.surcharges.large_province_enabled' => false]);

        $q = $this->quote('post_intercity_d3_parcel', 1000, self::MASHHAD);

        $this->assertSame(0, $q->deliverySurcharge);
        $this->assertSame(0, $q->largeProvinceSurcharge);
        // The 20 % Tehran/Alborz rule has no switch and still applies.
        $this->assertSame(18000, $this->quote('post_intercity_d3_parcel', 1000, self::TEHRAN)->deliverySurcharge);
    }

    #[Test]
    public function percentages_are_read_from_shipping_parameters(): void
    {
        ShippingParameter::where(['carrier' => 'post', 'key' => 'metropolitan_percentage'])->update(['value' => '10']);

        $this->assertSame(10000, $this->quote('post_intercity_d3_parcel', 1000, self::AHVAZ)->deliverySurcharge);
    }

    #[Test]
    public function a_missing_required_parameter_withholds_the_quote_instead_of_pricing_with_zero(): void
    {
        ShippingParameter::where(['carrier' => 'post', 'key' => 'metropolitan_percentage'])->delete();

        $this->assertUnavailable(Reason::PricingParameterMissing, fn () => $this->quote('post_intercity_d3_parcel', 1000, self::AHVAZ));
        // A route that does not need that parameter is still priced.
        $this->assertSame(100000, $this->quote('post_intercity_d3_parcel', 1000, self::DEZFUL)->basePrice);
    }

    // ── Island, fragile, ancillary ─────────────────────────────────────────────

    #[Test]
    public function island_fee_is_per_started_kilogram_and_excluded_from_percentage_bases(): void
    {
        // Dezful stands in for an explicitly approved island destination.
        config(['shipping.surcharges.island_city_ids' => [self::DEZFUL]]);

        $q = $this->quote('post_intercity_d3_parcel', 1590, self::DEZFUL);

        $this->assertSame(2 * 25500, $q->islandSurcharge);
        $this->assertSame(117500, $q->postalServiceCharge());
        // Taxed, but no percentage is computed on it.
        $this->assertSame(intdiv((117500 + 51000) * 10, 100), $q->postalTax);
    }

    #[Test]
    public function no_island_fee_without_explicit_membership(): void
    {
        $this->assertSame(0, $this->quote('post_intercity_d3_parcel', 1590, self::DEZFUL)->islandSurcharge);
    }

    #[Test]
    public function fragile_is_conditional_and_25_percent_of_the_service_charge(): void
    {
        $this->assertSame(0, $this->quote('post_intercity_d3_parcel', 1001)->fragileSurcharge);

        $q = $this->quote('post_intercity_d3_parcel', 1001, overrides: ['fragile' => true]);
        $this->assertSame(intdiv(117500 * 25, 100), $q->fragileSurcharge);
    }

    #[Test]
    public function electronic_service_applies_to_registered_non_priority_only(): void
    {
        $this->assertSame(7700, $this->quote('post_registered_parcel', 2500)->electronicServiceCharge);
        // Priority services already include electronic id + SMS.
        $this->assertSame(0, $this->quote('post_intercity_d3_parcel', 1000)->electronicServiceCharge);

        config(['shipping.post_1405.electronic_service.enabled' => false]);
        $this->assertSame(0, $this->quote('post_registered_parcel', 2500)->electronicServiceCharge);
    }

    #[Test]
    public function compensation_is_off_by_default_and_method_specific_when_enabled(): void
    {
        $this->assertSame(0, $this->quote('post_registered_parcel', 2500)->compensation);

        config(['shipping.post_1405.compensation.enabled' => true]);
        $this->assertSame(10000, $this->quote('post_registered_parcel', 2500)->compensation);
        $this->assertSame(0, $this->quote('post_intercity_d3_parcel', 1000)->compensation);
    }

    // ── Postal tax & packaging ─────────────────────────────────────────────────

    #[Test]
    public function the_spec_example_qom_to_ahvaz_910g_d3_parcel(): void
    {
        $q = $this->quote('post_intercity_d3_parcel', 910, self::AHVAZ);

        $this->assertSame([
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
        ], $q->toArray());
    }

    #[Test]
    public function postal_tax_is_exactly_10_percent_floored_to_whole_tomans(): void
    {
        // 44,900 + 7,705 electronic = 52,605 → 5,260.5 → 5,260.
        config(['shipping.post_1405.electronic_service.price_toman' => 7705]);

        $q = $this->quote('post_registered_parcel', 2500);

        $this->assertSame(5260, $q->postalTax);
        $this->assertSame(44900 + 7705 + 5260 + 50000, $q->total());
    }

    #[Test]
    public function postal_tax_covers_the_configured_components_only(): void
    {
        // Mashhad: 100,000 + 15,000 metro + 5,000 large → tax 12,000.
        $this->assertSame(12000, $this->quote('post_intercity_d3_parcel', 1000, self::MASHHAD)->postalTax);

        config(['shipping.post_1405.postal_tax_components' => ['postal_service_charge']]);
        $this->assertSame(10000, $this->quote('post_intercity_d3_parcel', 1000, self::MASHHAD)->postalTax);
    }

    #[Test]
    public function packaging_is_flat_untaxed_and_outside_every_percentage_base(): void
    {
        $default = $this->quote('post_intercity_d3_parcel', 910, self::AHVAZ);
        config(['shipping.post_1405.packaging_price_toman' => 77777]);
        $changed = $this->quote('post_intercity_d3_parcel', 910, self::AHVAZ);

        $this->assertSame(50000, $default->packaging);
        $this->assertSame(77777, $changed->packaging);
        $this->assertSame($default->postalTax, $changed->postalTax);
        $this->assertSame($default->deliverySurcharge, $changed->deliverySurcharge);
        $this->assertSame($default->total() + 27777, $changed->total());

        // Adding `packaging` to the taxable list is ignored — it is never taxed.
        config(['shipping.post_1405.postal_tax_components' => ['postal_service_charge', 'delivery_surcharge', 'packaging']]);
        $this->assertSame(11500, $this->quote('post_intercity_d3_parcel', 910, self::AHVAZ)->postalTax);
    }

    #[Test]
    public function every_amount_is_an_integer_and_the_total_is_the_sum_of_its_parts(): void
    {
        config(['shipping.surcharges.island_city_ids' => [self::SHIRAZ], 'shipping.post_1405.compensation.enabled' => true]);

        $q = $this->quote('post_registered_parcel', 7777, self::SHIRAZ, ['fragile' => true]);
        $parts = $q->toArray();
        $total = array_pop($parts);

        foreach ($q->toArray() as $value) {
            $this->assertIsInt($value);
        }
        $this->assertSame(array_sum($parts), $total);
    }

    // ── Geography validation ───────────────────────────────────────────────────

    #[Test]
    public function an_unknown_destination_is_never_guessed(): void
    {
        $this->assertUnavailable(Reason::DestinationIncomplete, fn () => $this->quote('post_intercity_d3_parcel', 1000, null));
        $this->assertUnavailable(Reason::DestinationIncomplete, fn () => $this->quote('post_intercity_d3_parcel', 1000, overrides: ['destinationCityId' => null]));
    }

    #[Test]
    public function an_unconfigured_origin_disables_postal_pricing(): void
    {
        $this->assertUnavailable(Reason::OriginNotConfigured, fn () => $this->quote('post_intercity_d3_parcel', 1000, overrides: ['originProvinceId' => null]));
        $this->assertUnavailable(Reason::OriginNotConfigured, fn () => $this->quote('post_intercity_d3_parcel', 1000, overrides: ['originCityId' => null]));
    }

    // ── Eligibility & route coverage ───────────────────────────────────────────

    #[Test]
    public function a_disabled_toggle_blocks_the_quote(): void
    {
        config(['shipping.post_1405.services.post_intercity_d3_parcel' => false]);

        $this->assertUnavailable(Reason::ServiceDisabled, fn () => $this->quote('post_intercity_d3_parcel', 1000));
    }

    #[Test]
    public function derived_services_are_never_priced(): void
    {
        $this->enable('post_mbag_registered', 'post_periodical');

        // Goods are not printed matter…
        $this->assertUnavailable(Reason::ContentNotEligible, fn () => $this->quote('post_mbag_registered', 1000));
        // …and even printed matter has no resolvable derived tariff.
        $this->assertUnavailable(Reason::DerivedTariffUnresolved, fn () => $this->quote('post_mbag_registered', 1000, overrides: ['contentKind' => 'printed_matter']));
        $this->assertUnavailable(Reason::DerivedTariffUnresolved, fn () => $this->quote('post_periodical', 1000, overrides: ['contentKind' => 'periodical']));
    }

    #[Test]
    public function d1_and_d2_need_an_explicit_route_allowlist(): void
    {
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d1_parcel', 1000, self::AHVAZ));
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d2_parcel', 1000, self::AHVAZ));

        config([
            'shipping.post_1405.coverage.intercity_d1.province_ids' => [13],
            'shipping.post_1405.coverage.intercity_d2.city_ids' => [self::AHVAZ],
        ]);

        $this->assertSame(153000, $this->quote('post_intercity_d1_parcel', 1000, self::AHVAZ)->basePrice);
        $this->assertSame(153000, $this->quote('post_intercity_d1_parcel', 1000, self::DEZFUL)->basePrice);
        $this->assertSame(110000, $this->quote('post_intercity_d2_parcel', 1000, self::AHVAZ)->basePrice);
        // City-level allow-list: the rest of the province is still not covered.
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d2_parcel', 1000, self::DEZFUL));
    }

    #[Test]
    public function intercity_same_day_needs_route_approval_a_known_carton_and_at_most_10kg(): void
    {
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ));

        config(['shipping.post_1405.coverage.intercity_sameday.city_ids' => [self::AHVAZ]]);
        $this->assertUnavailable(Reason::CartonSizeUnknown, fn () => $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ));

        $this->assertUnavailable(Reason::CartonTooLarge, fn () => $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ, ['cartonSize' => 7]));

        $this->assertSame(287000, $this->quote('post_intercity_sameday_parcel', 1000, self::AHVAZ, ['cartonSize' => 6])->basePrice);
        $this->assertSame(287000 + 9 * 21500, $this->quote('post_intercity_sameday_parcel', 10000, self::AHVAZ, ['cartonSize' => 6])->postalServiceCharge());
        $this->assertUnavailable(Reason::WeightNotSupported, fn () => $this->quote('post_intercity_sameday_parcel', 10001, self::AHVAZ, ['cartonSize' => 6]));
    }

    #[Test]
    public function intercity_services_do_not_serve_the_origin_city(): void
    {
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_intercity_d3_parcel', 1000, self::QOM));
    }

    #[Test]
    public function urban_services_need_same_city_coverage_and_an_explicit_zone(): void
    {
        // Not the origin city → never urban.
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_urban_3h_parcel', 1000, self::AHVAZ));
        // Origin city but no confirmed coverage.
        $this->assertUnavailable(Reason::RouteNotSupported, fn () => $this->quote('post_urban_3h_parcel', 1000, self::QOM));

        config(['shipping.post_1405.coverage.urban_3h.city_ids' => [self::QOM]]);
        // Covered, but the 15 % metropolitan list is NOT a rate-zone classification.
        $this->assertUnavailable(Reason::ZoneUnclassified, fn () => $this->quote('post_urban_3h_parcel', 1000, self::QOM));

        config(['shipping.post_1405.urban_zones.metropolis' => [self::QOM]]);
        $q = $this->quote('post_urban_3h_parcel', 1001, self::QOM);
        $this->assertSame('metropolis', $q->rateZone);
        $this->assertSame(118000 + 14000, $q->postalServiceCharge());

        // A city in two zone lists is ambiguous → unavailable.
        config(['shipping.post_1405.urban_zones.province_center' => [self::QOM]]);
        $this->assertUnavailable(Reason::ZoneUnclassified, fn () => $this->quote('post_urban_3h_parcel', 1000, self::QOM));
    }

    #[Test]
    public function a_missing_tariff_is_never_replaced_by_a_fallback_price(): void
    {
        PostalTariff1405::where('service_code', 'post_intercity_d3_parcel')->delete();

        $this->assertUnavailable(Reason::TariffMissing, fn () => $this->quote('post_intercity_d3_parcel', 1000));
    }

    #[Test]
    public function an_overlapping_hand_edited_bracket_is_ambiguous_and_not_quoted(): void
    {
        PostalTariff1405::create([
            'service_code' => 'post_registered_parcel', 'zone_code' => 'non_neighbor', 'weight_from' => 2500, 'weight_to' => 2600,
            'base_price_toman' => 1, 'extra_per_started_kg_toman' => 0, 'extra_above_grams' => null, 'tariff_year' => 1405,
        ]);

        $this->assertUnavailable(Reason::TariffMissing, fn () => $this->quote('post_registered_parcel', 2550));
        $this->assertSame(44900, $this->quote('post_registered_parcel', 2400)->basePrice);
    }
}
