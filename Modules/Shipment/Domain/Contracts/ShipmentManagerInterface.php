<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Contracts;

use DateTimeInterface;
use Modules\Shipment\Domain\DTOs\DeliverySlotDTO;
use Modules\Shipment\Domain\DTOs\DeliverySlotReservationDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;

/**
 * The public boundary of the Shipment module. Every other module (notably Order)
 * interacts with fulfillment exclusively through this contract + immutable DTOs.
 */
interface ShipmentManagerInterface
{
    /**
     * Config-backed fulfillment methods with capabilities calculated for the
     * given user/address (e.g. local delivery marked unavailable when ineligible).
     *
     * @return ShipmentMethodDTO[]
     */
    public function getAvailableMethods(int $userId, ?int $addressId): array;

    /**
     * Bookable local-delivery sessions grouped by date for the selectable window.
     *
     * @return array<int, array{date: string, slots: DeliverySlotDTO[]}>
     */
    public function getAvailableDeliverySlots(int $userId, int $addressId, DateTimeInterface $from, DateTimeInterface $until): array;

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
}
