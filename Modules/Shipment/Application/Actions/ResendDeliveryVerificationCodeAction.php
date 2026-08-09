<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Events\DeliveryVerificationCodeIssuedEvent;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Services\DeliveryVerificationCodeService;

/**
 * Recovery for the code that never arrived.
 *
 * Because only a hash is stored, there is nothing to look up and re-read to the
 * customer — the only honest recovery is to mint a *new* code, which by
 * definition retires the old one. That is a feature: a code read out over the
 * phone by whoever answered support stops being usable the moment it is replaced.
 *
 * SMS only. The customer was already told their order is on its way, so a second
 * "shipment sent" notification in the app would be noise about an event that has
 * not happened twice.
 */
class ResendDeliveryVerificationCodeAction
{
    public function __construct(
        private readonly ShipmentTransitionService $transitions,
        private readonly DeliveryVerificationCodeService $verificationCodes,
        private readonly OrderManagerInterface $orders,
    ) {}

    public function handle(int $shipmentId): ShipmentDTO
    {
        [$shipment, $code] = DB::transaction(function () use ($shipmentId): array {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            $this->assertResendable($shipment);

            ['code' => $code, 'attributes' => $attributes] = $this->verificationCodes->issue();

            $shipment->update($attributes);

            return [$shipment->fresh(), $code];
        });

        Event::dispatch(new DeliveryVerificationCodeIssuedEvent(
            shipmentId: $shipment->id,
            orderId: $shipment->order_id,
            userId: $shipment->user_id,
            deliveryCode: $code,
            orderPublicCode: $this->orders->findOrder($shipment->order_id)?->publicCode,
        ));

        return $this->transitions->toDTO($shipment);
    }

    /**
     * A code only means anything during an active attempt: before dispatch there
     * is nothing to confirm, and afterwards the shipment is settled.
     */
    private function assertResendable(Shipment $shipment): void
    {
        if ($shipment->method_type !== ShipmentMethodType::LocalDelivery->value) {
            throw ValidationException::withMessages([
                'shipment' => ['Only local delivery shipments use a delivery code.'],
            ]);
        }

        if (ShipmentStatus::from($shipment->status) !== ShipmentStatus::OutForDelivery) {
            throw ValidationException::withMessages([
                'shipment' => ['A delivery code can only be resent while the shipment is out for delivery.'],
            ]);
        }
    }
}
