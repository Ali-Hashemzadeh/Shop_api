<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Repositories;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\Contracts\LocalDeliveryEligibilityInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\DTOs\DeliverySlotReservationDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;
use Modules\Shipment\Domain\Services\ShipmentMethodRegistry;

class EloquentShipmentManager implements ShipmentManagerInterface
{
    public function __construct(
        private readonly ShipmentMethodRegistry $registry,
        private readonly LocalDeliveryEligibilityInterface $eligibility,
        private readonly DeliverySlotAvailabilityService $availability,
        private readonly ShipmentTransitionService $transitions,
    ) {}

    public function getAvailableMethods(int $userId, ?int $addressId): array
    {
        $addressSnapshot = $addressId !== null ? $this->findOwnedAddress($userId, $addressId) : null;

        $methods = [];

        foreach (array_keys($this->registry->all()) as $code) {
            $available = true;
            $reason = null;

            if ($this->registry->find($code)['type'] === ShipmentMethodType::LocalDelivery->value) {
                $eligible = $addressSnapshot !== null
                    && $this->eligibility->isEligible($addressSnapshot['province_id'] ?? null, $addressSnapshot['city_id'] ?? null);

                if (! $eligible) {
                    $available = false;
                    $reason = $addressSnapshot === null
                        ? 'Select an address to check local delivery availability.'
                        : 'Local delivery is not available for this address.';
                }
            }

            $methods[] = $this->registry->toDTO($code, $available, $reason);
        }

        return $methods;
    }

    public function getAvailableDeliverySlots(int $userId, int $addressId, DateTimeInterface $from, DateTimeInterface $until): array
    {
        // Address ownership is verified so slots are only listed for a real address.
        $this->findOwnedAddress($userId, $addressId);

        return $this->availability->listGrouped(
            Carbon::instance($from),
            Carbon::instance($until),
        );
    }

    public function validateSelection(int $userId, string $methodCode, ?int $addressId, ?int $deliverySlotId): ShipmentSelectionDTO
    {
        $method = $this->registry->find($methodCode);

        if ($method === null) {
            throw ValidationException::withMessages([
                'shipment_method_code' => ['The selected shipment method is invalid.'],
            ]);
        }

        $requiresAddress = (bool) $method['requires_address'];
        $requiresSlot = (bool) $method['requires_delivery_slot'];
        $type = (string) $method['type'];

        $address = null;
        $deliverySlotSnapshot = null;
        $pickupLocation = null;

        // Delivery slot is prohibited unless the method requires it.
        if (! $requiresSlot && $deliverySlotId !== null) {
            throw ValidationException::withMessages([
                'delivery_slot_id' => ['This shipment method does not accept a delivery time.'],
            ]);
        }

        if ($requiresAddress) {
            if ($addressId === null) {
                throw ValidationException::withMessages([
                    'address_id' => ['An address is required for this shipment method.'],
                ]);
            }

            $address = $this->findOwnedAddress($userId, $addressId);
        }

        if ($type === ShipmentMethodType::LocalDelivery->value) {
            if (! $this->eligibility->isEligible($address['province_id'] ?? null, $address['city_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'address_id' => ['Local delivery is not available for this address.'],
                ]);
            }

            if ($deliverySlotId === null) {
                throw ValidationException::withMessages([
                    'delivery_slot_id' => ['A delivery time is required for local delivery.'],
                ]);
            }

            $slot = DeliverySlot::find($deliverySlotId);

            if ($slot === null || ! $this->availability->isSelectable($slot)) {
                throw ValidationException::withMessages([
                    'delivery_slot_id' => ['The selected delivery time is no longer available.'],
                ]);
            }

            $deliverySlotSnapshot = [
                'slot_id' => $slot->id,
                'date' => $slot->dateString(),
                'starts_at' => substr((string) $slot->starts_at, 0, 8),
                'ends_at' => substr((string) $slot->ends_at, 0, 8),
            ];
        }

        if ($type === ShipmentMethodType::Pickup->value) {
            $pickupLocation = $this->registry->pickupLocation($methodCode);
        }

        return new ShipmentSelectionDTO(
            methodCode: $methodCode,
            methodTitle: (string) $method['title'],
            methodType: $type,
            shippingCost: (int) $method['price'],
            requiresAddress: $requiresAddress,
            requiresDeliverySlot: $requiresSlot,
            addressId: $requiresAddress ? $addressId : null,
            deliverySlotId: $deliverySlotId,
            address: $address,
            deliverySlot: $deliverySlotSnapshot,
            pickupLocation: $pickupLocation,
        );
    }

    public function holdForPendingOrder(int $orderId, int $userId, ShipmentSelectionDTO $selection, DateTimeInterface $expiresAt): ?DeliverySlotReservationDTO
    {
        if ($selection->methodType !== ShipmentMethodType::LocalDelivery->value || $selection->deliverySlotId === null) {
            return null;
        }

        // Re-lock and re-check under a row lock to prevent overbooking.
        /** @var DeliverySlot|null $slot */
        $slot = DeliverySlot::lockForUpdate()->find($selection->deliverySlotId);

        if ($slot === null || $slot->status !== 'open' || ! $this->availability->isSelectable($slot)) {
            throw ValidationException::withMessages([
                'delivery_slot_id' => ['The selected delivery time is no longer available.'],
            ]);
        }

        if ($this->availability->remainingCapacity($slot) <= 0) {
            throw ValidationException::withMessages([
                'delivery_slot_id' => ['The selected delivery time is no longer available.'],
            ]);
        }

        $reservation = DeliverySlotReservation::create([
            'delivery_slot_id' => $slot->id,
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => ReservationStatus::Held->value,
            'expires_at' => $expiresAt,
        ]);

        return DeliverySlotReservationDTO::fromModel($reservation);
    }

    public function releasePendingOrder(int $orderId): void
    {
        DeliverySlotReservation::where('order_id', $orderId)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->update([
                'status' => ReservationStatus::Released->value,
                'released_at' => now(),
            ]);
    }

    public function activateForPaidOrder(int $orderId, int $userId, array $shipmentSnapshot): ?ShipmentDTO
    {
        $existing = Shipment::where('order_id', $orderId)->first();

        if ($existing !== null) {
            return $this->transitions->toDTO($existing);
        }

        // No shipment selection captured on the order — nothing to activate.
        if (empty($shipmentSnapshot['method_code'])) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $userId, $shipmentSnapshot): ShipmentDTO {
            try {
                $shipment = Shipment::create([
                    'public_code' => Shipment::generateUniquePublicCode(),
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'method_code' => $shipmentSnapshot['method_code'],
                    'method_title' => $shipmentSnapshot['method_title'],
                    'method_type' => $shipmentSnapshot['method_type'],
                    'shipping_cost' => (int) ($shipmentSnapshot['shipping_cost'] ?? 0),
                    'status' => ShipmentStatus::Pending->value,
                    'address_snapshot' => $shipmentSnapshot['address'] ?? null,
                    'delivery_slot_snapshot' => $shipmentSnapshot['delivery_slot'] ?? null,
                    'pickup_location_snapshot' => $shipmentSnapshot['pickup_location'] ?? null,
                ]);
            } catch (QueryException $e) {
                // Concurrent activation (e.g. duplicate payment callback) — unique
                // order_id lost the race. Return the shipment the winner created.
                $shipment = Shipment::where('order_id', $orderId)->firstOrFail();

                return $this->transitions->toDTO($shipment);
            }

            ShipmentStatusHistory::create([
                'shipment_id' => $shipment->id,
                'from_status' => null,
                'to_status' => ShipmentStatus::Pending->value,
                'created_at' => now(),
            ]);

            // Confirm the held local-delivery slot for this order (if any).
            DeliverySlotReservation::where('order_id', $orderId)
                ->where('status', ReservationStatus::Held->value)
                ->update([
                    'status' => ReservationStatus::Confirmed->value,
                    'confirmed_at' => now(),
                ]);

            return $this->transitions->toDTO($shipment->fresh());
        });
    }

    public function findForOrder(int $orderId): ?ShipmentDTO
    {
        $shipment = Shipment::where('order_id', $orderId)->first();

        return $shipment ? $this->transitions->toDTO($shipment) : null;
    }

    /**
     * Load an address owned by the user and return an immutable snapshot with
     * resolved province/city names. Throws ValidationException when not owned.
     *
     * @return array<string, mixed>
     */
    private function findOwnedAddress(int $userId, int $addressId): array
    {
        $address = DB::table('addresses')->where('id', $addressId)->first();

        if ($address === null || (int) $address->user_id !== $userId) {
            throw ValidationException::withMessages([
                'address_id' => ['The selected address is invalid.'],
            ]);
        }

        $provinceName = $address->province_id
            ? optional(DB::table('provinces')->where('id', $address->province_id)->first())->name
            : null;
        $cityName = $address->city_id
            ? optional(DB::table('cities')->where('id', $address->city_id)->first())->name
            : null;

        return [
            'address_id' => $address->id,
            'province_id' => $address->province_id,
            'province_name' => $provinceName,
            'city_id' => $address->city_id,
            'city_name' => $cityName,
            'postal_code' => $address->postal_code,
            'address' => $address->address,
        ];
    }
}
