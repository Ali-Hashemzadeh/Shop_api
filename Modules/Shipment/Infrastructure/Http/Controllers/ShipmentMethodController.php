<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Infrastructure\Http\Resources\DeliverySlotResource;
use Modules\Shipment\Infrastructure\Http\Resources\ShipmentMethodResource;

class ShipmentMethodController extends Controller
{
    public function __construct(
        private readonly ShipmentManagerInterface $shipment,
    ) {}

    /**
     * List the fixed, config-backed fulfillment methods with capabilities
     * calculated for the (optional) address.
     */
    public function index(Request $request): JsonResponse
    {
        $addressId = $request->filled('address_id') ? (int) $request->query('address_id') : null;

        $methods = $this->shipment->getAvailableMethods($request->user()->id, $addressId);

        return response()->json([
            'data' => ShipmentMethodResource::collection($methods)->resolve(),
        ]);
    }

    /**
     * List bookable local-delivery sessions grouped by date for the address.
     */
    public function deliverySlots(Request $request): JsonResponse
    {
        $request->validate([
            'address_id' => ['required', 'integer'],
            'from' => ['sometimes', 'date'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::today();
        $days = (int) $request->query('days', 7);
        $until = $from->copy()->addDays($days - 1)->endOfDay();

        $groups = $this->shipment->getAvailableDeliverySlots(
            userId: $request->user()->id,
            addressId: (int) $request->query('address_id'),
            from: $from,
            until: $until,
        );

        $data = array_map(static fn (array $group): array => [
            'date' => $group['date'],
            'slots' => DeliverySlotResource::collection($group['slots'])->resolve(),
        ], $groups);

        return response()->json(['data' => $data]);
    }
}
