<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Shipment\Application\Actions\MarkShipmentDeliveredAction;
use Modules\Shipment\Domain\DTOs\DeliveryAssignmentShipmentDTO;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Infrastructure\Http\Requests\DeliveryMarkDeliveredRequest;
use Modules\Shipment\Infrastructure\Http\Requests\IndexDeliveryShipmentsRequest;
use Modules\Shipment\Infrastructure\Http\Resources\DeliveryAssignmentShipmentResource;

/**
 * The delivery worker's own surface. Couriers never touch `/admin/shipments`:
 * that surface answers for every shipment in the store, and its resource keeps
 * growing with operational internals a driver has no business reading.
 *
 * Every lookup here starts from `assignedShipments()`, so scoping is not a check
 * that can be forgotten on one endpoint — a shipment belonging to another driver
 * is simply not in the query, and reads as 404 rather than 403. Answering 403
 * would confirm the shipment exists, turning the public-code space into an
 * oracle for anybody holding a driver token.
 */
class DeliveryShipmentController extends Controller
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
    ) {}

    public function index(IndexDeliveryShipmentsRequest $request): JsonResponse
    {
        $perPage = min(max((int) $request->validated('per_page', 15), 1), 100);

        $paginator = $this->assignedShipments($request)
            ->orderByDesc('id')
            ->when(
                $request->filled('status'),
                fn (Builder $q) => $q->where('status', $request->validated('status')),
            )
            ->paginate($perPage)
            ->through(fn (Shipment $s) => $this->toDTO($s));

        return response()->json(
            DeliveryAssignmentShipmentResource::collection($paginator)->response()->getData(true),
        );
    }

    public function show(Request $request, string $publicCode): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.delivery.view-assigned'), 403);

        $shipment = $this->assignedShipments($request)->where('public_code', $publicCode)->firstOrFail();

        return response()->json(new DeliveryAssignmentShipmentResource($this->toDTO($shipment)));
    }

    /**
     * Close the delivery with the code the customer read out. Resolving through
     * the scoped query first means a driver cannot even *attempt* a code against
     * somebody else's shipment — the guess never reaches the verification path,
     * and the confirmation throttle is not spent on it either.
     */
    public function markDelivered(
        DeliveryMarkDeliveredRequest $request,
        string $publicCode,
        MarkShipmentDeliveredAction $action,
    ): JsonResponse {
        $shipment = $this->assignedShipments($request)->where('public_code', $publicCode)->firstOrFail();

        $dto = $action->handle(
            shipmentId: $shipment->id,
            operatorId: (int) $request->user()->id,
            receiverName: $request->validated('receiver_name'),
            note: $request->validated('note'),
            code: (string) $request->validated('code'),
            mustBeAssignedTo: (int) $request->user()->id,
        );

        $shipment = Shipment::findOrFail($dto->id);

        return response()->json(new DeliveryAssignmentShipmentResource($this->toDTO($shipment)));
    }

    /** The only query the driver surface ever starts from. */
    private function assignedShipments(Request $request): Builder
    {
        return Shipment::query()
            ->where('assigned_delivery_user_id', (int) $request->user()->id)
            ->where('method_type', ShipmentMethodType::LocalDelivery->value);
    }

    private function toDTO(Shipment $shipment): DeliveryAssignmentShipmentDTO
    {
        // Customer contact details arrive through Identity's contract, never from
        // a join against its tables.
        $customer = $this->identity->getUserSummary((int) $shipment->user_id);

        return new DeliveryAssignmentShipmentDTO(
            id: $shipment->id,
            publicCode: $shipment->public_code,
            status: ShipmentStatus::from($shipment->status),
            addressSnapshot: $shipment->address_snapshot,
            deliverySlotSnapshot: $shipment->delivery_slot_snapshot,
            customerName: trim(implode(' ', array_filter([$customer->name, $customer->lastName]))) ?: null,
            customerPhone: $customer->phone,
            receiverName: $shipment->receiver_name,
            failureReason: $shipment->failure_reason,
            note: $shipment->note,
            assignedAt: $shipment->delivery_assigned_at,
            outForDeliveryAt: $shipment->out_for_delivery_at,
            deliveredAt: $shipment->delivered_at,
        );
    }
}
