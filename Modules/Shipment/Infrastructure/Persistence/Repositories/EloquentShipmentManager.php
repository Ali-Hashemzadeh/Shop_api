<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Repositories;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\Contracts\LocalDeliveryEligibilityInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Domain\DTOs\DeliverySlotReservationDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;
use Modules\Shipment\Domain\DTOs\ShipmentParcelDTO;
use Modules\Shipment\Domain\DTOs\ShipmentSelectionDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostBreakdownDTO;
use Modules\Shipment\Domain\DTOs\ShippingCostRequestDTO;
use Modules\Shipment\Domain\DTOs\ShippingQuoteDTO;
use Modules\Shipment\Domain\Enums\PackageType;
use Modules\Shipment\Domain\Enums\ReservationStatus;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Enums\ShippingPricingMode;
use Modules\Shipment\Domain\Exceptions\PostalServiceUnavailableException;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Domain\Models\DeliverySlotReservation;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalUnavailableReason;
use Modules\Shipment\Domain\Services\MissingWeightFallbackPolicy;
use Modules\Shipment\Domain\Services\ShipmentMethodRegistry;

class EloquentShipmentManager implements ShipmentManagerInterface
{
    private const WEIGHT_MISSING_FALLBACK_MESSAGE = 'An item in your cart has no shipping weight, so weight-based postal services are not available. Please refresh your shipping options and choose Normal Post (پست عادی).';

    public function __construct(
        private readonly ShipmentMethodRegistry $registry,
        private readonly LocalDeliveryEligibilityInterface $eligibility,
        private readonly DeliverySlotAvailabilityService $availability,
        private readonly ShipmentTransitionService $transitions,
        private readonly IdentityManagerInterface $identity,
        private readonly ShippingCostCalculatorInterface $shippingCalculator,
        private readonly MissingWeightFallbackPolicy $fallback,
    ) {}

    public function getAvailableMethods(int $userId, ?int $addressId, ?ShipmentParcelDTO $parcel = null): array
    {
        $addressSnapshot = $addressId !== null ? $this->findOwnedAddress($userId, $addressId) : null;
        $parcel ??= ShipmentParcelDTO::empty();
        $fallbackApplies = $this->fallback->appliesTo($parcel);

        $methods = [];

        // A cart with a missing item weight is offered Normal Post — the only postal
        // option it can buy. It needs an address, like every postal method.
        if ($fallbackApplies && $addressSnapshot !== null) {
            $methods[] = $this->fallbackMethod($addressSnapshot, $parcel);
        }

        foreach ($this->registry->all() as $code => $method) {
            if ($addressSnapshot === null && (bool) ($method['requires_address'] ?? false)) {
                continue;
            }

            $type = (string) $method['type'];

            if ($type === ShipmentMethodType::Postal->value) {
                $methods[] = $this->postalMethod($code, $addressSnapshot, $parcel, $fallbackApplies);

                continue;
            }

            $available = true;
            $reason = null;

            if ($type === ShipmentMethodType::LocalDelivery->value) {
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

    /**
     * One postal method for the list: priced by the same path checkout uses, or
     * reported unavailable with a machine-readable code and a null price.
     *
     * With a missing item weight the method stays `weight_missing` (never priced from
     * the remaining known weights); when the Normal Post fallback is offered instead,
     * the reason says so.
     *
     * @param  array<string, mixed>|null  $address
     */
    private function postalMethod(string $code, ?array $address, ShipmentParcelDTO $parcel, bool $fallbackApplies): ShipmentMethodDTO
    {
        try {
            $breakdown = $this->quotePostal($code, $address, $parcel);

            return $this->registry->toDTO($code, true, null, $breakdown->total(), [
                'weightGrams' => $breakdown->weightGrams,
                'rateZone' => $breakdown->rateZone,
                'priceBreakdown' => $breakdown->toArray(),
            ]);
        } catch (PostalServiceUnavailableException $e) {
            $reason = $e->reason === PostalUnavailableReason::WeightMissing && $fallbackApplies
                ? self::WEIGHT_MISSING_FALLBACK_MESSAGE
                : $e->reason->message();

            return $this->registry->toDTO($code, false, $reason, null, [
                'unavailableCode' => $e->reason->value,
                'weightGrams' => $parcel->skusMissingWeight() === [] && ! $parcel->isEmpty() ? $parcel->totalWeightGrams() : null,
                'rateZone' => $e->rateZone,
                'missingWeightSkus' => $e->skus,
            ]);
        }
    }

    /**
     * The Normal Post fallback entry for the list: priced by the same path checkout
     * uses (quoteFallback), or reported unavailable with a reason code.
     *
     * @param  array<string, mixed>  $address
     */
    private function fallbackMethod(array $address, ShipmentParcelDTO $parcel): ShipmentMethodDTO
    {
        $code = MissingWeightFallbackPolicy::METHOD_CODE;
        $mode = ShippingPricingMode::FixedMissingWeightFallback->value;

        try {
            $price = $this->quoteFallback($address, $parcel);

            return $this->registry->toDTO($code, true, null, $price, [
                'pricingMode' => $mode,
                'priceBreakdown' => ShippingQuoteDTO::fixedBreakdown($price),
            ]);
        } catch (PostalServiceUnavailableException $e) {
            return $this->registry->toDTO($code, false, $e->reason->message(), null, [
                'pricingMode' => $mode,
                'unavailableCode' => $e->reason->value,
            ]);
        }
    }

    public function getAvailableDeliverySlots(
        int $userId,
        int $addressId,
        DateTimeInterface $from,
        DateTimeInterface $until,
        string $sort = 'date',
        string $direction = 'asc',
    ): array {
        // Address ownership is verified so slots are only listed for a real address.
        $this->findOwnedAddress($userId, $addressId);

        return $this->availability->listGrouped(
            Carbon::instance($from),
            Carbon::instance($until),
            sort: $sort,
            direction: $direction,
        );
    }

    public function validateSelection(int $userId, string $methodCode, ?int $addressId, ?int $deliverySlotId): ShipmentSelectionDTO
    {
        // The retired post_standard code is let through ONLY as the enabled Normal
        // Post fallback. This method has no cart, so it cannot tell whether the
        // fallback applies — quoteSelection() makes that authoritative check against
        // the re-read parcel before anything is written. post_express never passes.
        $method = $this->registry->find($methodCode)
            ?? ($this->fallback->isFallbackMethod($methodCode) && $this->fallback->isEnabled()
                ? $this->registry->describe($methodCode)
                : null);

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

        // A missing-weight Normal Post fallback is allowed in the local-delivery
        // area as well. Local delivery and pickup remain separate choices; blocking
        // the fallback here made it visible but unselectable for Qom addresses.
        // quoteSelection() remains authoritative about the cart weight and address.

        if ($type === ShipmentMethodType::Pickup->value) {
            $pickupLocation = $this->registry->pickupLocation($methodCode);
        }

        return new ShipmentSelectionDTO(
            methodCode: $methodCode,
            methodTitle: (string) $method['title'],
            methodType: $type,
            // Postal methods have no static price: 0 here is a placeholder and the
            // charged amount always comes from quoteSelection().
            shippingCost: (int) ($method['price'] ?? 0),
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
                // createWithPublicCode assigns the `bds-` code via the model hook and
                // retries internally on a public_code collision, so the catch below
                // only ever sees the duplicate-order_id case it was written for.
                $shipment = Shipment::createWithPublicCode([
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'method_code' => $shipmentSnapshot['method_code'],
                    'method_title' => $shipmentSnapshot['method_title'],
                    'method_type' => $shipmentSnapshot['method_type'],
                    // Copied from the checkout snapshot, never recalculated: packaging
                    // and postal tax (or the flat Normal Post fallback price) were
                    // charged once, at checkout.
                    'shipping_cost' => (int) ($shipmentSnapshot['shipping_cost'] ?? 0),
                    'price_breakdown' => $shipmentSnapshot['price_breakdown'] ?? null,
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

    public function quoteSelection(ShipmentSelectionDTO $selection, ShipmentParcelDTO $parcel): ShippingQuoteDTO
    {
        // Local delivery and pickup keep their static configured price — no
        // packaging, no postal tax.
        if ($selection->methodType !== ShipmentMethodType::Postal->value) {
            return new ShippingQuoteDTO($selection->shippingCost);
        }

        // Normal Post fallback: the flat configured price is the final charge, and
        // only for a parcel that really has a missing weight.
        if ($this->fallback->isFallbackMethod($selection->methodCode)) {
            try {
                $price = $this->quoteFallback($selection->address, $parcel);
            } catch (PostalServiceUnavailableException $e) {
                throw $this->checkoutValidationError($e);
            }

            return ShippingQuoteDTO::fixedMissingWeightFallback($price);
        }

        try {
            $breakdown = $this->quotePostal($selection->methodCode, $selection->address, $parcel);
        } catch (PostalServiceUnavailableException $e) {
            throw $this->checkoutValidationError($e);
        }

        return new ShippingQuoteDTO($breakdown->total(), $breakdown);
    }

    /**
     * The single Normal Post fallback pricing path shared by the method list and
     * checkout. Requires a complete destination (but permits Qom addresses alongside
     * local delivery), and then
     * returns the configured flat price — no tariff, no surcharge, no postal tax, no
     * packaging, and never the weight of only the items that do have one.
     *
     * @param  array<string, mixed>|null  $address
     *
     * @throws PostalServiceUnavailableException
     */
    private function quoteFallback(?array $address, ShipmentParcelDTO $parcel): int
    {
        $price = $this->fallback->price();

        if (! $this->fallback->isEnabled() || $price === null) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::ServiceDisabled);
        }

        if (empty($address['province_id']) || empty($address['city_id'])) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::DestinationIncomplete);
        }

        if ($parcel->isEmpty()) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::CartEmpty);
        }

        // Every weight is valid → the 1405 methods apply, never the fallback.
        if ($parcel->skusMissingWeight() === []) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::FallbackNotApplicable);
        }

        return $price;
    }

    /**
     * The single postal pricing path shared by the method list and checkout, so the
     * price a customer sees is the price they are charged. Fail-closed: throws when
     * anything required for a real quote is missing.
     *
     * @param  array<string, mixed>|null  $address
     *
     * @throws PostalServiceUnavailableException
     */
    private function quotePostal(string $methodCode, ?array $address, ShipmentParcelDTO $parcel): ShippingCostBreakdownDTO
    {
        // Retired legacy codes (post_standard/post_express) are never priced again.
        if (Post1405Catalog::find($methodCode) === null) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::UnknownService);
        }

        // No local-delivery-area exclusion here: local delivery and the 1405 methods
        // are evaluated independently. Same-city Qom can buy urban post beside local
        // delivery; another city in Qom province is an intercity (same_province)
        // destination even when a province-level local-delivery area covers it. The
        // urban/intercity split itself is the calculator's job.

        if ($parcel->isEmpty()) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::CartEmpty);
        }

        // An unknown weight is never treated as zero.
        $missing = $parcel->skusMissingWeight();
        if ($missing !== []) {
            throw new PostalServiceUnavailableException(PostalUnavailableReason::WeightMissing, skus: $missing);
        }

        return $this->shippingCalculator->calculate(new ShippingCostRequestDTO(
            serviceCode: $methodCode,
            weightGrams: $parcel->totalWeightGrams(),
            originProvinceId: config('shipping.origin_province_id'),
            originCityId: config('shipping.origin_city_id'),
            destinationProvinceId: isset($address['province_id']) ? (int) $address['province_id'] : null,
            destinationCityId: isset($address['city_id']) ? (int) $address['city_id'] : null,
            fragile: config('shipping.default_package_type', PackageType::Standard->value) === PackageType::Fragile->value,
            contentKind: (string) config('shipping.post_1405.content_kind', Post1405Catalog::CONTENT_GOODS),
            cartonSize: config('shipping.post_1405.carton_size'),
        ));
    }

    /** Map an unquotable postal selection onto the 422 field the client can fix. */
    private function checkoutValidationError(PostalServiceUnavailableException $e): ValidationException
    {
        // A weight-based method for a cart that now has to use Normal Post (the
        // weight may have been removed after the list was fetched).
        if ($e->reason === PostalUnavailableReason::WeightMissing && $this->fallback->isEnabled()) {
            return ValidationException::withMessages([
                'shipment_method_code' => [self::WEIGHT_MISSING_FALLBACK_MESSAGE],
            ]);
        }

        if ($e->reason === PostalUnavailableReason::WeightMissing) {
            $messages = [];
            foreach ($e->skus as $sku) {
                $messages["items.{$sku}.weight_grams"] = ["Shipping weight is not set for item {$sku}, so postage cannot be calculated."];
            }

            return ValidationException::withMessages($messages);
        }

        $field = $e->reason === PostalUnavailableReason::DestinationIncomplete ? 'address_id' : 'shipment_method_code';

        return ValidationException::withMessages([$field => [$e->reason->message()]]);
    }

    /**
     * Whether an address falls inside the configured local-delivery service area.
     *
     * Returns false when no service area is configured: isEligible() is permissive
     * by design in that case, and treating "everyone is eligible" as "everyone is
     * inside the zone" would withdraw post from every customer in the country.
     * A null address cannot be located, so it is never inside.
     *
     * @param  array<string, mixed>|null  $address
     */
    private function isInsideServiceArea(?array $address): bool
    {
        return $address !== null
            && $this->eligibility->hasServiceArea()
            && $this->eligibility->isEligible($address['province_id'] ?? null, $address['city_id'] ?? null);
    }

    /**
     * Load an address owned by the user and return the immutable snapshot that
     * gets frozen onto the shipment. Throws ValidationException when the address
     * does not exist or belongs to somebody else — one message for both, so an
     * id cannot be probed for existence.
     *
     * The lookup goes through Identity's contract rather than a query against its
     * tables, which is also what lets the snapshot carry the map coordinates: the
     * courier navigates to the pin the customer dropped at checkout, and later
     * edits to that address never move an already-placed delivery.
     *
     * @return array<string, mixed>
     */
    private function findOwnedAddress(int $userId, int $addressId): array
    {
        $snapshot = $this->identity->getOwnedAddressSnapshot($userId, $addressId);

        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'address_id' => ['The selected address is invalid.'],
            ]);
        }

        return $snapshot->toArray();
    }
}
