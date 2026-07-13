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
use Modules\Order\Domain\Exceptions\InvalidAddressException;
use Modules\Order\Infrastructure\Http\Requests\StoreOrderRequest;
use Modules\Order\Infrastructure\Http\Resources\OrderResource;

class OrderController extends Controller
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly CancelOrderAction $cancelOrder,
        private readonly OrderManagerInterface $manager,
    ) {}

    public function store(StoreOrderRequest $request): JsonResponse
    {
        try {
            $dto = $this->createOrder->handle(
                userId: $request->user()->id,
                addressId: (int) $request->input('address_id'),
                shipmentMethodId: (int) $request->input('shipment_method_id'),
                notes: $request->input('notes'),
            );
        } catch (EmptyCartException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InvalidAddressException $e) {
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
