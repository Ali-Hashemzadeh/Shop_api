<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Infrastructure\Http\Resources\ShipmentResource;

class ShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentTransitionService $transitions,
    ) {}

    /** Customer reads their own shipment by public code. */
    public function show(Request $request, string $publicCode): JsonResponse
    {
        $shipment = Shipment::where('public_code', $publicCode)->firstOrFail();

        $this->assertVisible($request, $shipment);

        return response()->json(new ShipmentResource($this->transitions->toDTO($shipment)));
    }

    /** Customer reads the shipment attached to one of their orders. */
    public function showForOrder(Request $request, int $order): JsonResponse
    {
        $shipment = Shipment::where('order_id', $order)->firstOrFail();

        $this->assertVisible($request, $shipment);

        return response()->json(new ShipmentResource($this->transitions->toDTO($shipment)));
    }

    private function assertVisible(Request $request, Shipment $shipment): void
    {
        $user = $request->user();

        // Owner may always view; operators need the admin-view permission.
        if ((int) $shipment->user_id === (int) $user->id) {
            abort_unless((bool) $user->can('shipment.view-own'), 403);

            return;
        }

        abort_unless((bool) $user->can('shipment.view-admin'), 403);
    }
}
