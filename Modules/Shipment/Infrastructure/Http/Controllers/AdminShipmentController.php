<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shipment\Application\Actions\ConfirmPickupAction;
use Modules\Shipment\Application\Actions\HandShipmentToPostAction;
use Modules\Shipment\Application\Actions\MarkDeliveryFailedAction;
use Modules\Shipment\Application\Actions\MarkLocalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkPickupReadyAction;
use Modules\Shipment\Application\Actions\MarkPostalShipmentReadyAction;
use Modules\Shipment\Application\Actions\MarkShipmentDeliveredAction;
use Modules\Shipment\Application\Actions\MarkShipmentOutForDeliveryAction;
use Modules\Shipment\Application\Actions\RescheduleLocalDeliveryAction;
use Modules\Shipment\Application\Actions\StartPreparingShipmentAction;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Infrastructure\Http\Requests\HandToPostRequest;
use Modules\Shipment\Infrastructure\Http\Requests\IndexAdminShipmentsRequest;
use Modules\Shipment\Infrastructure\Http\Requests\MarkDeliveredRequest;
use Modules\Shipment\Infrastructure\Http\Requests\MarkDeliveryFailedRequest;
use Modules\Shipment\Infrastructure\Http\Requests\RescheduleDeliveryRequest;
use Modules\Shipment\Infrastructure\Http\Resources\ShipmentResource;

class AdminShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentTransitionService $transitions,
    ) {}

    public function index(IndexAdminShipmentsRequest $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = Shipment::query()->orderByDesc('id');

        $request->whenFilled('status', fn ($v) => $query->where('status', $v));
        $request->whenFilled('method_code', fn ($v) => $query->where('method_code', $v));
        $request->whenFilled('method_type', fn ($v) => $query->where('method_type', $v));
        $request->whenFilled('order_id', fn ($v) => $query->where('order_id', (int) $v));
        $request->whenFilled('tracking_number', fn ($v) => $query->where('tracking_number', $v));
        $request->whenFilled('delivery_slot_id', fn ($v) => $query->where('delivery_slot_snapshot->slot_id', (int) $v));
        $request->whenFilled('delivery_date', fn ($v) => $query->where('delivery_slot_snapshot->date', $v));
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('created_at', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('created_at', '<=', $v));

        $paginator = $query->paginate($perPage)
            ->through(fn (Shipment $s) => ShipmentDTO::fromModel($s));

        return response()->json(ShipmentResource::collection($paginator)->response()->getData(true));
    }

    public function show(Request $request, string $publicCode): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.view-admin'), 403);

        $shipment = $this->resolve($publicCode);

        return $this->respond($this->transitions->toDTO($shipment));
    }

    public function startPreparing(Request $request, string $publicCode, StartPreparingShipmentAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.start-preparing');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('note'));

        return $this->respond($dto);
    }

    public function markReadyForPost(Request $request, string $publicCode, MarkPostalShipmentReadyAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.post.mark-ready');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('note'));

        return $this->respond($dto);
    }

    public function handToPost(HandToPostRequest $request, string $publicCode, HandShipmentToPostAction $action): JsonResponse
    {
        $dto = $action->handle(
            shipmentId: $this->resolve($publicCode)->id,
            operatorId: $request->user()->id,
            trackingNumber: (string) $request->input('tracking_number'),
            carrierName: $request->input('carrier_name'),
            note: $request->input('note'),
            postalReceiptMediaId: $request->filled('postal_receipt_media_id') ? (int) $request->input('postal_receipt_media_id') : null,
        );

        return $this->respond($dto);
    }

    public function markReadyForDispatch(Request $request, string $publicCode, MarkLocalShipmentReadyAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.delivery.mark-ready');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('note'));

        return $this->respond($dto);
    }

    public function markOutForDelivery(Request $request, string $publicCode, MarkShipmentOutForDeliveryAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.delivery.dispatch');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('note'));

        return $this->respond($dto);
    }

    public function markDelivered(MarkDeliveredRequest $request, string $publicCode, MarkShipmentDeliveredAction $action): JsonResponse
    {
        $dto = $action->handle(
            shipmentId: $this->resolve($publicCode)->id,
            operatorId: $request->user()->id,
            receiverName: $request->input('receiver_name'),
            note: $request->input('note'),
            proofMediaId: $request->filled('proof_media_id') ? (int) $request->input('proof_media_id') : null,
        );

        return $this->respond($dto);
    }

    public function markDeliveryFailed(MarkDeliveryFailedRequest $request, string $publicCode, MarkDeliveryFailedAction $action): JsonResponse
    {
        $dto = $action->handle(
            shipmentId: $this->resolve($publicCode)->id,
            operatorId: $request->user()->id,
            failureReason: (string) $request->input('failure_reason'),
            note: $request->input('note'),
        );

        return $this->respond($dto);
    }

    public function reschedule(RescheduleDeliveryRequest $request, string $publicCode, RescheduleLocalDeliveryAction $action): JsonResponse
    {
        $dto = $action->handle(
            shipmentId: $this->resolve($publicCode)->id,
            operatorId: $request->user()->id,
            newSlotId: (int) $request->input('delivery_slot_id'),
            note: $request->input('note'),
        );

        return $this->respond($dto);
    }

    public function markReadyForPickup(Request $request, string $publicCode, MarkPickupReadyAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.pickup.mark-ready');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('note'));

        return $this->respond($dto);
    }

    public function confirmPickup(Request $request, string $publicCode, ConfirmPickupAction $action): JsonResponse
    {
        $this->guard($request, 'shipment.pickup.complete');
        $dto = $action->handle($this->resolve($publicCode)->id, $request->user()->id, $request->input('receiver_name'), $request->input('note'));

        return $this->respond($dto);
    }

    private function resolve(string $publicCode): Shipment
    {
        return Shipment::where('public_code', $publicCode)->firstOrFail();
    }

    private function guard(Request $request, string $permission): void
    {
        abort_unless((bool) $request->user()?->can($permission), 403);
    }

    private function respond(ShipmentDTO $dto): JsonResponse
    {
        return response()->json(new ShipmentResource($dto));
    }
}
