<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Contracts;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Domain\DTOs\DeliverySlotDTO;
use Modules\Shipment\Domain\DTOs\DeliverySlotReservationDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;
use Modules\Shipment\Domain\DTOs\ShipmentParcelDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;
use Modules\Shipment\Domain\DTOs\ShippingQuoteDTO;

/**
 * The public boundary of the Shipment module. Every other module (notably Order)
 * interacts with fulfillment exclusively through this contract + immutable DTOs.
 */
interface ShipmentManagerInterface
{
    /**
     * Enabled fulfillment methods with capabilities calculated for the given
     * user/address/parcel. Every enabled Iran Post 1405 method is priced through the
     * exact path checkout uses (quoteSelection) and carries its own itemised price;
     * a postal method that cannot be priced is returned with available=false,
     * price=null and a machine-readable unavailable code — never a fallback price.
     *
     * @return ShipmentMethodDTO[]
     */
    public function getAvailableMethods(int $userId, ?int $addressId, ?ShipmentParcelDTO $parcel = null): array;

    /**
     * Bookable local-delivery sessions grouped by date for the selectable window.
     *
     * @return array<int, array{date: string, slots: DeliverySlotDTO[]}>
     */
    public function getAvailableDeliverySlots(
        int $userId,
        int $addressId,
        DateTimeInterface $from,
        DateTimeInterface $until,
        string $sort = 'date',
        string $direction = 'asc',
    ): array;

    /**
     * Validate a checkout selection against method capabilities, address ownership
     * and eligibility, and slot bookability. Throws ValidationException on failure.
     */
    public function validateSelection(int $userId, string $methodCode, ?int $addressId, ?int $deliverySlotId): ShipmentSelectionDTO;

    /**
     * Place a held delivery-slot reservation for a pending order (local delivery
     * only). Re-locks and re-checks slot capacity. Returns null for methods that
     * do not consume a slot (postal/pickup).
     */
    public function holdForPendingOrder(int $orderId, int $userId, ShipmentSelectionDTO $selection, DateTimeInterface $expiresAt): ?DeliverySlotReservationDTO;

    /**
     * Release any active (held/confirmed) delivery-slot reservation for an order
     * that has moved to a terminal unpaid state. Idempotent and safe to repeat.
     */
    public function releasePendingOrder(int $orderId): void;

    /**
     * Create the operational shipment record for a newly-paid order and confirm
     * its held delivery slot (local delivery only). Idempotent — repeated calls
     * never create a duplicate shipment or duplicate initial history. Returns null
     * when the order carries no shipment selection to activate.
     */
    public function activateForPaidOrder(int $orderId, int $userId, array $shipmentSnapshot): ?ShipmentDTO;

    public function findForOrder(int $orderId): ?ShipmentDTO;

    /**
     * The authoritative, server-side shipping price (TOMANS) for a validated selection
     * and the parcel actually being bought.
     *
     * Postal methods are priced by the Iran Post 1405 engine (base + surcharges +
     * ancillary fees + 10 % postal tax + flat packaging) and FAIL CLOSED: a disabled
     * method, an unsupported route, a missing tariff, or any item without a positive
     * shipping weight throws a ValidationException (422) — there is no static-price
     * fallback. Local delivery and pickup return their static configured price.
     *
     * Order/Cart supply only primitives (SKU, quantity, unit weight) and never compute
     * a shipping price themselves. A client-submitted price is never consulted.
     *
     * @throws ValidationException
     */
    public function quoteSelection(ShipmentSelectionDTO $selection, ShipmentParcelDTO $parcel): ShippingQuoteDTO;
}
