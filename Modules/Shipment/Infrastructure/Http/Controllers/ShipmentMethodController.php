<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\DTOs\ShipmentParcelDTO;
use Modules\Shipment\Infrastructure\Http\Resources\DeliverySlotResource;
use Modules\Shipment\Infrastructure\Http\Resources\ShipmentMethodResource;

class ShipmentMethodController extends Controller
{
    public function __construct(
        private readonly ShipmentManagerInterface $shipment,
        private readonly CartManagerInterface $cart,
        private readonly CatalogManagerInterface $catalog,
    ) {}

    /**
     * List enabled fulfillment methods for the (optional) address.
     *
     * Every enabled Iran Post 1405 method is priced for the caller's actual cart with
     * the same engine checkout uses, and carries its own `price` + `price_breakdown`
     * (integer tomans). A postal method that cannot be priced is returned with
     * `available: false`, `price: null` and an `unavailable_code`. Without an
     * `address_id` only methods that need no address are returned.
     *
     * When any cart item has a missing / zero / negative shipping weight, the 1405
     * methods are all `available: false` (`weight_missing`) and Normal Post
     * (`post_standard`, `pricing_mode: fixed_missing_weight_fallback`) is offered at
     * the configured flat price instead — the final shipping charge. Local delivery
     * and pickup are unaffected.
     *
     * @response array{data: ShipmentMethodResource[]}
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'address_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $addressId = $request->filled('address_id') ? (int) $request->query('address_id') : null;

        $userId = $request->user()->id;
        $cart = $this->cart->findOrCreateCart($userId, null);

        // Use the same authoritative Catalog weight source as CreateOrderAction.
        // Cart display DTO weights can be stale or diverge from a freshly re-read
        // variant; if these paths disagree, Normal Post appears selectable in the
        // methods endpoint but quoteSelection() rejects it at checkout.
        $skus = array_values(array_unique(array_map(
            static fn ($item): string => $item->sku,
            $cart->items,
        )));
        $variantsBySku = $this->catalog->getVariantsBySkus($skus);

        // Shipment receives primitives only — never Cart or Catalog DTOs.
        // A missing variant has no trustworthy weight, so it must not be given a
        // fabricated positive weight. Checkout independently rejects missing SKUs.
        $parcel = ShipmentParcelDTO::fromLines(array_map(
            static fn ($item): array => [
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'weight_grams' => $variantsBySku[$item->sku]->weightGrams ?? null,
            ],
            $cart->items,
        ));

        $methods = $this->shipment->getAvailableMethods($userId, $addressId, $parcel);

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
            'sort' => ['sometimes', 'string', Rule::in(['date', 'starts_at', 'remaining_capacity', 'capacity', 'created_at'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : Carbon::today();
        $days = (int) $request->query('days', 7);
        $until = $from->copy()->addDays($days - 1)->endOfDay();

        $groups = $this->shipment->getAvailableDeliverySlots(
            userId: $request->user()->id,
            addressId: (int) $request->query('address_id'),
            from: $from,
            until: $until,
            sort: (string) $request->query('sort', 'date'),
            direction: $request->filled('sort') ? (string) $request->query('direction', 'asc') : 'asc',
        );

        $data = array_map(static fn (array $group): array => [
            'date' => $group['date'],
            'slots' => DeliverySlotResource::collection($group['slots'])->resolve(),
        ], $groups);

        return response()->json(['data' => $data]);
    }
}
