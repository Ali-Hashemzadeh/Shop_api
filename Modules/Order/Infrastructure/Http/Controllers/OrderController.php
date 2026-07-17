<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Order\Application\Actions\CancelOrderAction;
use Modules\Order\Application\Actions\CreateOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Exceptions\EmptyCartException;
use Modules\Order\Infrastructure\Http\Requests\StoreOrderRequest;
use Modules\Order\Infrastructure\Http\Resources\OrderResource;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;

class OrderController extends Controller
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly CancelOrderAction $cancelOrder,
        private readonly OrderManagerInterface $manager,
        private readonly ShipmentManagerInterface $shipment,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        // Validate + resolve the shipment selection (address ownership/eligibility,
        // slot bookability). Throws ValidationException (422) on failure.
        $selection = $this->shipment->validateSelection(
            userId: $request->user()->id,
            methodCode: (string) $request->input('shipment_method_code'),
            addressId: $request->filled('address_id') ? (int) $request->input('address_id') : null,
            deliverySlotId: $request->filled('delivery_slot_id') ? (int) $request->input('delivery_slot_id') : null,
        );

        try {
            $dto = $this->createOrder->handle(
                userId: $request->user()->id,
                selection: $selection,
                notes: $request->input('notes'),
            );
        } catch (EmptyCartException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new OrderResource($dto), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginator = $this->manager->getUserOrders($request->user()->id, $perPage);

        return response()->json(OrderResource::collection($paginator)->response()->getData(true));
    }

    public function cancel(Request $request, int $order): JsonResponse
    {
        $dto = $this->cancelOrder->handle(
            orderId: $order,
            userId: $request->user()->id,
        );

        return response()->json(new OrderResource($dto));
    }
}
