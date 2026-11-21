<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Modules\Shipment\Domain\Enums\ShippingPricingMode;

/**
 * The authoritative shipping price for a validated checkout selection, returned by
 * ShipmentManagerInterface::quoteSelection(). Order writes `total` to
 * orders.shipping_cost and merges toSnapshot() into the order's shipment snapshot;
 * activation then copies it to the shipment unchanged (never recalculated, so the
 * packaging fee and postal tax are charged exactly once).
 */
final class ShippingQuoteDTO
{
    public function __construct(
        public readonly int $total,
        public readonly ?ShippingCostBreakdownDTO $breakdown = null,
        public readonly ?ShippingPricingMode $pricingMode = null,
    ) {}

    /**
     * The flat Normal Post fallback quote: `total` is the final charge, with no
     * tariff, surcharge, postal tax or packaging on top.
     */
    public static function fixedMissingWeightFallback(int $price): self
    {
        return new self($price, null, ShippingPricingMode::FixedMissingWeightFallback);
    }

    /**
     * The `price_breakdown` shape of the fixed fallback (method list, order snapshot,
     * shipment). Deliberately not the 1405 shape, so it can never be read as an
     * official weight-based tariff quote.
     *
     * @return array{fixed_shipping_price: int, total: int}
     */
    public static function fixedBreakdown(int $price): array
    {
        return ['fixed_shipping_price' => $price, 'total' => $price];
    }

    /**
     * Keys merged into orders.shipment_snapshot.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        if ($this->pricingMode === ShippingPricingMode::FixedMissingWeightFallback) {
            return [
                'shipping_cost' => $this->total,
                'currency' => 'TOMAN',
                'pricing_mode' => $this->pricingMode->value,
                'tariff_year' => null,
                'rate_zone' => null,
                'weight_grams' => null,
                'price_breakdown' => self::fixedBreakdown($this->total),
            ];
        }

        if ($this->breakdown === null) {
            return ['shipping_cost' => $this->total, 'currency' => 'TOMAN'];
        }

        return [
            'shipping_cost' => $this->total,
            'currency' => 'TOMAN',
            'tariff_year' => $this->breakdown->tariffYear,
            'rate_zone' => $this->breakdown->rateZone,
            'weight_grams' => $this->breakdown->weightGrams,
            'delivery_surcharge_rule' => $this->breakdown->deliverySurchargeRule,
            'price_breakdown' => $this->breakdown->toArray(),
        ];
    }
}
