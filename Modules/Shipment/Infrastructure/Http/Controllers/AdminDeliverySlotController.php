<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shipment\Application\Actions\CloseDeliverySlotAction;
use Modules\Shipment\Application\Actions\OpenDeliverySlotAction;
use Modules\Shipment\Application\Actions\UpdateDeliverySlotCapacityAction;
use Modules\Shipment\Application\Services\DeliverySlotAvailabilityService;
use Modules\Shipment\Domain\DTOs\DeliverySlotDTO;
use Modules\Shipment\Domain\Models\DeliverySlot;
use Modules\Shipment\Infrastructure\Http\Requests\UpdateDeliverySlotRequest;
use Modules\Shipment\Infrastructure\Http\Resources\DeliverySlotResource;

class AdminDeliverySlotController extends Controller
{
    public function __construct(
        private readonly DeliverySlotAvailabilityService $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.slot.view-admin'), 403);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        $query = DeliverySlot::query()->orderBy('delivery_date')->orderBy('starts_at');
        $request->whenFilled('date_from', fn ($v) => $query->whereDate('delivery_date', '>=', $v));
        $request->whenFilled('date_to', fn ($v) => $query->whereDate('delivery_date', '<=', $v));
        $request->whenFilled('status', fn ($v) => $query->where('status', $v));

        $paginator = $query->paginate($perPage)->through(function (DeliverySlot $slot): DeliverySlotDTO {
            $remaining = $this->availability->remainingCapacity($slot);

            return DeliverySlotDTO::fromModel($slot, max($remaining, 0), $this->availability->isSelectable($slot));
        });

        return response()->json(DeliverySlotResource::collection($paginator)->response()->getData(true));
    }

    public function update(UpdateDeliverySlotRequest $request, int $slot, UpdateDeliverySlotCapacityAction $action): JsonResponse
    {
        $model = $action->handle(
            slotId: $slot,
            capacity: $request->filled('capacity') ? (int) $request->input('capacity') : null,
            adminReservedCapacity: $request->filled('admin_reserved_capacity') ? (int) $request->input('admin_reserved_capacity') : null,
            note: $request->input('note'),
        );

        return $this->respond($model);
    }

    public function close(Request $request, int $slot, CloseDeliverySlotAction $action): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.slot.close'), 403);

        return $this->respond($action->handle($slot, $request->input('note')));
    }

    public function open(Request $request, int $slot, OpenDeliverySlotAction $action): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.slot.close'), 403);

        return $this->respond($action->handle($slot, $request->input('note')));
    }

    private function respond(DeliverySlot $slot): JsonResponse
    {
        $remaining = $this->availability->remainingCapacity($slot);
        $dto = DeliverySlotDTO::fromModel($slot, max($remaining, 0), $this->availability->isSelectable($slot));

        return response()->json(new DeliverySlotResource($dto));
    }
}
