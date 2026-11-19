<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

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
    ) {}

    /**
     * Keys merged into orders.shipment_snapshot.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
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
