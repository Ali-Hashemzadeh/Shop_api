<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Shipping\Post;

use Illuminate\Support\Facades\Log;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\Exceptions\PostalServiceUnavailableException;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalScope;
use Modules\Shipment\Domain\Postal\PostalServiceDefinition;
use Modules\Shipment\Domain\Postal\PostalUnavailableReason as Reason;
use Modules\Shipment\Domain\Postal\PostalZone;
use Modules\Shipment\Infrastructure\Persistence\Repositories\PostalTariff1405Repository;
use Modules\Shipment\Infrastructure\Persistence\Repositories\ShippingParameterRepository;

/**
 * The Iran Post 1405 pricing engine — the only place a postal price is produced.
 * Integer TOMANS throughout; no float ever touches a price.
 *
 * FAIL-CLOSED: every condition that cannot be resolved from approved data throws
 * PostalServiceUnavailableException. There is no static-price fallback, no guessed
 * zone, and route coverage is assumed only when the shop explicitly opts into
 * open-route mode (shipping.post_1405.allow_unverified_routes).
 *
 *   1. Eligibility   enabled toggle → content kind → derived? → weight limits
 *   2. Geography     origin + destination province AND city must be known
 *   3. Rate zone     urban: same city + configured city zone lists (+ coverage*)
 *                    intercity: different city + Identity's province adjacency
 *                    (+ route allow-list*) + carton rule
 *                    * not required in open-route mode
 *   4. Base postage  closed bracket lookup; open-ended brackets add each STARTED kg
 *                    above extra_above_grams
 *   5. Surcharges    each % of the POSTAL SERVICE CHARGE (base + extra weight):
 *                    delivery area 20 % Tehran/Alborz XOR 15 % metropolitan,
 *                    large province 5 %, fragile 25 %. Island = per started kg flat.
 *   6. Ancillary     electronic id + SMS (registered non-priority), compensation
 *   7. Postal tax    floor(Σ configured taxable components × 10 / 100)
 *   8. Packaging     flat shop fee, once, untaxed
 *
 * ROUNDING: every percentage component is floored to a whole toman individually
 * (intdiv), before being summed.
 */
class PostShippingCalculator implements ShippingCostCalculatorInterface
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
        private readonly PostalTariff1405Repository $tariffs,
        private readonly ShippingParameterRepository $parameters,
    ) {}

    public function calculate(ShippingCostRequestDTO $request): ShippingCostBreakdownDTO
    {
        $service = Post1405Catalog::find($request->serviceCode)
            ?? throw new PostalServiceUnavailableException(Reason::UnknownService);

        $this->assertEligible($service, $request);

        $zone = $this->resolveZone($service, $request);
        $year = (int) config('shipping.post_1405.tariff_year', Post1405Catalog::TARIFF_YEAR);

        $tariff = $this->tariffs->findBracket($service->code, $zone, $request->weightGrams, $year);

        if ($tariff === null) {
            throw new PostalServiceUnavailableException(Reason::TariffMissing, rateZone: $zone);
        }

        // ── Base postage + extra started kilograms ────────────────────────────────
        $basePrice = (int) $tariff->base_price_toman;
        $extraWeight = 0;

        if ($tariff->weight_to === null && $tariff->extra_per_started_kg_toman > 0) {
            if ($tariff->extra_above_grams === null) {
                // An open-ended rate without its threshold cannot be applied safely.
                throw new PostalServiceUnavailableException(Reason::TariffMissing, 'Open-ended tariff has no extra_above_grams.', rateZone: $zone);
            }

            $extraWeight = $this->startedKilograms($request->weightGrams - (int) $tariff->extra_above_grams)
                * (int) $tariff->extra_per_started_kg_toman;
        }

        $serviceCharge = $basePrice + $extraWeight;

        // ── Geographic surcharges (all on the postal service charge) ──────────────
        [$deliverySurcharge, $deliveryRule] = $this->deliverySurcharge($request, $serviceCharge);
        $largeProvinceSurcharge = $this->largeProvinceSurcharge($request, $serviceCharge);
        $islandSurcharge = $this->islandSurcharge($request);
        $fragileSurcharge = $request->fragile
            ? $this->percentOf($serviceCharge, $this->requiredParameter('fragile_percentage'))
            : 0;

        // ── Ancillary postal fees ─────────────────────────────────────────────────
        $electronicService = $service->electronicService && (bool) config('shipping.post_1405.electronic_service.enabled', false)
            ? max(0, (int) config('shipping.post_1405.electronic_service.price_toman', 0))
            : 0;

        $compensation = 0;
        if ($service->compensationKind !== null && (bool) config('shipping.post_1405.compensation.enabled', false)) {
            $compensation = max(0, (int) config("shipping.post_1405.compensation.{$service->compensationKind}_toman", 0));
        }

        // ── Postal tax on the configured taxable components (never packaging) ─────
        $components = [
            'postal_service_charge' => $serviceCharge,
            'delivery_surcharge' => $deliverySurcharge,
            'large_province_surcharge' => $largeProvinceSurcharge,
            'island_surcharge' => $islandSurcharge,
            'fragile_surcharge' => $fragileSurcharge,
            'electronic_service_charge' => $electronicService,
            'compensation' => $compensation,
        ];

        $taxable = 0;
        foreach ((array) config('shipping.post_1405.postal_tax_components', []) as $key) {
            $taxable += $components[$key] ?? 0;
        }

        $postalTax = $this->percentOf($taxable, (int) config('shipping.post_1405.postal_tax_percent', 10));
        $packaging = max(0, (int) config('shipping.post_1405.packaging_price_toman', 0));

        return new ShippingCostBreakdownDTO(
            serviceCode: $service->code,
            tariffYear: $year,
            rateZone: $zone,
            weightGrams: $request->weightGrams,
            basePrice: $basePrice,
            extraWeight: $extraWeight,
            deliverySurcharge: $deliverySurcharge,
            deliverySurchargeRule: $deliveryRule,
            largeProvinceSurcharge: $largeProvinceSurcharge,
            islandSurcharge: $islandSurcharge,
            fragileSurcharge: $fragileSurcharge,
            electronicServiceCharge: $electronicService,
            compensation: $compensation,
            postalTax: $postalTax,
            packaging: $packaging,
        );
    }

    private function assertEligible(PostalServiceDefinition $service, ShippingCostRequestDTO $request): void
    {
        if (! (bool) config("shipping.post_1405.services.{$service->code}", false)) {
            throw new PostalServiceUnavailableException(Reason::ServiceDisabled);
        }

        // A parcel of goods is never re-classified as a document/envelope.
        if ($service->contentKind !== $request->contentKind) {
            throw new PostalServiceUnavailableException(Reason::ContentNotEligible);
        }

        if ($service->isDerived()) {
            throw new PostalServiceUnavailableException(Reason::DerivedTariffUnresolved);
        }

        if ($request->weightGrams <= 0) {
            throw new PostalServiceUnavailableException(Reason::WeightMissing);
        }

        if ($service->maxWeightGrams !== null && $request->weightGrams > $service->maxWeightGrams) {
            throw new PostalServiceUnavailableException(Reason::WeightNotSupported);
        }

        if ($request->originProvinceId === null || $request->originCityId === null) {
            throw new PostalServiceUnavailableException(Reason::OriginNotConfigured);
        }

        if ($request->destinationProvinceId === null || $request->destinationCityId === null) {
            throw new PostalServiceUnavailableException(Reason::DestinationIncomplete);
        }
    }

    /**
     * The rate zone this service prices the route in, after its coverage policy has
     * accepted the route. Never guessed.
     */
    private function resolveZone(PostalServiceDefinition $service, ShippingCostRequestDTO $request): string
    {
        $sameCity = $request->destinationCityId === $request->originCityId;

        if ($service->scope === PostalScope::Urban) {
            // Urban services deliver within the origin city only — open-route mode
            // never relaxes this; it only drops the coverage allow-list.
            if (! $sameCity || ! $this->routePermitted($service, $request)) {
                throw new PostalServiceUnavailableException(Reason::RouteNotSupported);
            }

            return $this->urbanZone((int) $request->destinationCityId);
        }

        // Intercity priority services are between cities; same-city is urban.
        if ($service->scope === PostalScope::Intercity && $sameCity) {
            throw new PostalServiceUnavailableException(Reason::RouteNotSupported);
        }

        $zone = $this->identity->getProvinceDistanceType($request->originProvinceId, $request->destinationProvinceId);

        if (! in_array($zone, PostalZone::distanceCodes(), true)) {
            throw new PostalServiceUnavailableException(Reason::ZoneUnclassified);
        }

        if ($service->eligibilityPolicy === PostalServiceDefinition::POLICY_ROUTE_ALLOWLIST && ! $this->routePermitted($service, $request)) {
            throw new PostalServiceUnavailableException(Reason::RouteNotSupported, rateZone: $zone);
        }

        // Carton rule (intercity same-day): enforced in every mode, including open-route.
        if ($service->maxCartonSize !== null) {
            if ($request->cartonSize === null) {
                throw new PostalServiceUnavailableException(Reason::CartonSizeUnknown, rateZone: $zone);
            }

            if ($request->cartonSize > $service->maxCartonSize) {
                throw new PostalServiceUnavailableException(Reason::CartonTooLarge, rateZone: $zone);
            }
        }

        return $zone;
    }

    /**
     * Whether the shop offers this service on this route. In open-route mode
     * (shipping.post_1405.allow_unverified_routes) the explicit allow-list is not
     * required — a shop-side offer policy, not carrier confirmation. Everything else
     * (same-city/different-city, zone, carton, weight, tariff) is checked by the
     * callers regardless.
     */
    private function routePermitted(PostalServiceDefinition $service, ShippingCostRequestDTO $request): bool
    {
        if ((bool) config('shipping.post_1405.allow_unverified_routes', false)) {
            return true;
        }

        return $this->routeCovered($service, $request);
    }

    /** Whether the destination is on the service family's explicit coverage list. */
    private function routeCovered(PostalServiceDefinition $service, ShippingCostRequestDTO $request): bool
    {
        if ($service->coverageFamily === null) {
            return false;
        }

        $coverage = (array) config("shipping.post_1405.coverage.{$service->coverageFamily}", []);

        return in_array($request->destinationCityId, (array) ($coverage['city_ids'] ?? []), true)
            || in_array($request->destinationProvinceId, (array) ($coverage['province_ids'] ?? []), true);
    }

    /** Exactly one configured urban zone must contain the city. */
    private function urbanZone(int $cityId): string
    {
        $matches = [];

        foreach (PostalZone::urbanCodes() as $zone) {
            if (in_array($cityId, (array) config("shipping.post_1405.urban_zones.{$zone}", []), true)) {
                $matches[] = $zone;
            }
        }

        if (count($matches) !== 1) {
            throw new PostalServiceUnavailableException(Reason::ZoneUnclassified);
        }

        return $matches[0];
    }

    /**
     * 20 % for Tehran/Alborz provinces, otherwise 15 % for an approved metropolitan
     * city — never both.
     *
     * @return array{0: int, 1: ?string}
     */
    private function deliverySurcharge(ShippingCostRequestDTO $request, int $serviceCharge): array
    {
        if (in_array($request->destinationProvinceId, (array) config('shipping.surcharges.tehran_alborz_province_ids', []), true)) {
            return [$this->percentOf($serviceCharge, $this->requiredParameter('tehran_percentage')), 'tehran_alborz'];
        }

        if ((bool) config('shipping.surcharges.metropolitan_enabled', false)
            && in_array($request->destinationCityId, (array) config('shipping.surcharges.metropolitan_city_ids', []), true)) {
            return [$this->percentOf($serviceCharge, $this->requiredParameter('metropolitan_percentage')), 'metropolitan'];
        }

        return [0, null];
    }

    private function largeProvinceSurcharge(ShippingCostRequestDTO $request, int $serviceCharge): int
    {
        if (! (bool) config('shipping.surcharges.large_province_enabled', false)
            || ! in_array($request->destinationProvinceId, (array) config('shipping.surcharges.large_province_ids', []), true)) {
            return 0;
        }

        return $this->percentOf($serviceCharge, $this->requiredParameter('large_province_percentage'));
    }

    /** Flat fee per started kg of the whole parcel, for explicitly listed destinations. */
    private function islandSurcharge(ShippingCostRequestDTO $request): int
    {
        $isIsland = in_array($request->destinationCityId, (array) config('shipping.surcharges.island_city_ids', []), true)
            || in_array($request->destinationProvinceId, (array) config('shipping.surcharges.island_province_ids', []), true);

        return $isIsland
            ? $this->startedKilograms($request->weightGrams) * $this->requiredParameter('island_extra_per_kg')
            : 0;
    }

    /** A shipping_parameters value the quote cannot be computed without. */
    private function requiredParameter(string $key): int
    {
        $carrier = (string) config('shipping.carrier', 'post');
        $value = $this->parameters->integerOrNull($carrier, $key);

        if ($value === null) {
            Log::error('Postal pricing parameter missing; postal method withheld.', ['carrier' => $carrier, 'key' => $key]);

            throw new PostalServiceUnavailableException(Reason::PricingParameterMissing, "Missing shipping parameter {$carrier}.{$key}.");
        }

        return $value;
    }

    /** Started kilograms: 1 g → 1, 1000 g → 1, 1001 g → 2. Zero/negative → 0. */
    private function startedKilograms(int $grams): int
    {
        return $grams <= 0 ? 0 : intdiv($grams + 999, 1000);
    }

    /** floor(amount × percent / 100) in integer arithmetic. */
    private function percentOf(int $amount, int $percent): int
    {
        return $percent <= 0 || $amount <= 0 ? 0 : intdiv($amount * $percent, 100);
    }
}
