<?php

declare(strict_types=1);

namespace Modules\Shipment\Application\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Services\DeliveryVerificationCodeService;

/**
 * Close a delivery. The single completion path for both the assigned driver and
 * an admin — only *who is allowed to call it* differs, never what it checks.
 *
 * For a local delivery the customer's handoff code is mandatory on every route,
 * admin included. An admin override that skipped the code would quietly undo the
 * guarantee the code exists for: that somebody at the address confirmed receipt.
 * Admins are trusted with more shipments, not with fewer confirmations.
 *
 * Verification and the transition share one transaction and one row lock, so a
 * code can never be consumed by an attempt that then fails to complete, and two
 * concurrent submissions cannot both win. Everything that follows delivery —
 * `delivered_at`, history, the order moving to completed, the slot reservation
 * settling, the customer notification — stays where it already lived, in
 * ShipmentTransitionService.
 */
class MarkShipmentDeliveredAction
{
    public function __construct(
        private readonly ShipmentTransitionService $transitions,
        private readonly DeliveryVerificationCodeService $verificationCodes,
    ) {}

    /**
     * @param  string|null  $code  the customer's handoff code; required for local delivery
     * @param  int|null  $mustBeAssignedTo  when set, the caller must be the current
     *                                      assignee — the driver route passes its own
     *                                      user id, the admin route passes null
     */
    public function handle(
        int $shipmentId,
        int $operatorId,
        ?string $receiverName = null,
        ?string $note = null,
        ?int $proofMediaId = null,
        ?string $code = null,
        ?int $mustBeAssignedTo = null,
    ): ShipmentDTO {
        return DB::transaction(function () use ($shipmentId, $operatorId, $receiverName, $note, $proofMediaId, $code, $mustBeAssignedTo): ShipmentDTO {
            /** @var Shipment $shipment */
            $shipment = Shipment::lockForUpdate()->findOrFail($shipmentId);

            $this->assertAssignee($shipment, $mustBeAssignedTo);
            $this->assertVerified($shipment, $code);

            return $this->transitions->transition(
                shipmentId: $shipment->id,
                to: ShipmentStatus::Delivered,
                changedByUserId: $operatorId,
                note: $note,
                attributes: [
                    'receiver_name' => $receiverName,
                    'proof_media_id' => $proofMediaId,
                ],
                historyMeta: $receiverName !== null ? ['receiver_name' => $receiverName] : [],
            );
        });
    }

    /**
     * A driver who is not the current assignee must not be able to tell a real
     * shipment from an imaginary one, so this is a 404 and not a 403 — the same
     * answer the scoped lookup gives for a code that does not exist at all.
     */
    private function assertAssignee(Shipment $shipment, ?int $mustBeAssignedTo): void
    {
        if ($mustBeAssignedTo === null) {
            return;
        }

        if ((int) $shipment->assigned_delivery_user_id !== $mustBeAssignedTo) {
            throw (new ModelNotFoundException)->setModel(Shipment::class, [$shipment->id]);
        }
    }

    private function assertVerified(Shipment $shipment, ?string $code): void
    {
        if ($shipment->method_type !== ShipmentMethodType::LocalDelivery->value) {
            return;
        }

        if (ShipmentStatus::from($shipment->status) !== ShipmentStatus::OutForDelivery) {
            throw ValidationException::withMessages([
                'code' => ['This shipment is not out for delivery.'],
            ]);
        }

        // One message for every way a code can be wrong — unknown, stale, from a
        // previous attempt, never issued. Distinguishing them would tell a guesser
        // which shipments are worth guessing at.
        if ($code === null || ! $this->verificationCodes->matches($shipment, $code)) {
            throw ValidationException::withMessages([
                'code' => ['The delivery code is incorrect.'],
            ]);
        }
    }
}
