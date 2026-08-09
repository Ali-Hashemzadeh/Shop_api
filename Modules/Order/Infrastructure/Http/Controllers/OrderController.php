<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Order\Application\Actions\CancelOrderAction;
use Modules\Order\Application\Actions\CheckOrderCouponAction;
use Modules\Order\Application\Actions\CreateOrderAction;
use Modules\Order\Application\Actions\GetCustomerOrderDetailAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Exceptions\EmptyCartException;
use Modules\Order\Infrastructure\Http\Requests\CheckCouponRequest;
use Modules\Order\Infrastructure\Http\Requests\StoreOrderRequest;
use Modules\Order\Infrastructure\Http\Resources\CustomerOrderDetailResource;
use Modules\Order\Infrastructure\Http\Resources\OrderResource;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;

class OrderController extends Controller
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly CancelOrderAction $cancelOrder,
        private readonly CheckOrderCouponAction $checkCoupon,
        private readonly GetCustomerOrderDetailAction $getOrderDetail,
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
        // `search` is an exact order public code. Ownership is enforced inside the
        // manager and is never widened by the term.
        $paginator = $this->manager->getUserOrders(
            $request->user()->id,
            $perPage,
            $request->string('search')->trim()->toString() ?: null,
        );

        return response()->json(OrderResource::collection($paginator)->response()->getData(true));
    }

    public function show(Request $request, string $publicCode): JsonResponse
    {
        $detail = $this->getOrderDetail->handle(
            userId: $request->user()->id,
            publicCode: $publicCode,
        );

        abort_if($detail === null, 404, 'Order not found.');

        return response()->json(new CustomerOrderDetailResource($detail));
    }

    /**
     * Advisory coupon preview. Reserves nothing — see CheckOrderCouponAction.
     */
    public function checkCoupon(CheckCouponRequest $request, int $order): JsonResponse
    {
        return response()->json(
            $this->checkCoupon->handle(
                orderId: $order,
                userId: $request->user()->id,
                code: (string) $request->input('code'),
            )
        );
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
