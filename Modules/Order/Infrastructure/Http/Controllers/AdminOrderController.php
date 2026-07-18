<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Order\Application\Actions\AdminCancelOrderAction;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Infrastructure\Http\Requests\IndexAdminOrdersRequest;
use Modules\Order\Infrastructure\Http\Resources\AdminOrderListResource;
use Modules\Order\Infrastructure\Http\Resources\AdminOrderResource;

/**
 * Admin/operator order surface: view, search, and cancel only. Status transitions and
 * fulfillment progression are owned by the Shipment module and are intentionally absent
 * here — there is no create/edit/change-status endpoint.
 */
class AdminOrderController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly OrderManagerInterface $manager,
    ) {}

    public function index(IndexAdminOrdersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Order::class);

        $perPage = (int) $request->validated('per_page', 15);
        $filters = $request->safe()->only(['status', 'order_id', 'user_id', 'date_from', 'date_to']);

        $orders = $this->manager->getAdminOrders($filters, $perPage);

        return response()->json([
            'data' => AdminOrderListResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'from' => $orders->firstItem(),
                'to' => $orders->lastItem(),
            ],
            'links' => [
                'first' => $orders->url(1),
                'last' => $orders->url($orders->lastPage()),
                'prev' => $orders->previousPageUrl(),
                'next' => $orders->nextPageUrl(),
            ],
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorize('view', $order);

        return response()->json([
            'data' => new AdminOrderResource($this->manager->getAdminOrderDetail($order->id)),
        ]);
    }

    public function cancel(Order $order, AdminCancelOrderAction $action): JsonResponse
    {
        $this->authorize('cancel', $order);

        $action->handle($order->id);

        return response()->json([
            'data' => new AdminOrderResource($this->manager->getAdminOrderDetail($order->id)),
        ]);
    }
}
