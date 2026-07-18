<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Domain\DTOs\AdminOrderDetailDTO;

/**
 * Full admin order detail. Customer + item data come from the order's frozen snapshots
 * (customer_snapshot / product_snapshot). Shipping comes from the order's own
 * shipping_address + shipment_snapshot; the live fulfillment status comes from the
 * Shipment contract (null until the order is paid and a shipment activates).
 *
 * @mixin AdminOrderDetailDTO
 */
class AdminOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AdminOrderDetailDTO $detail */
        $detail = $this->resource;
        $order = $detail->order;
        $shipment = $detail->shipment;

        return [
            'id' => $order->id,
            'status' => $order->status->value,
            'total_amount' => $order->totalAmount,
            'shipping_cost' => $order->shippingCost,
            'tax_amount' => $order->taxAmount,
            'transaction_ref' => $order->transactionRef,
            'notes' => $order->notes,
            'created_at' => $order->createdAt->toISOString(),
            'customer' => $order->customerSnapshot,
            'shipping_address' => $order->shippingAddress,
            'shipment_snapshot' => $order->shipmentSnapshot,
            'shipment' => $shipment === null ? null : [
                'public_code' => $shipment->publicCode,
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->label(),
                'method_code' => $shipment->methodCode,
                'method_type' => $shipment->methodType,
                'tracking_number' => $shipment->trackingNumber,
            ],
            'items' => OrderItemResource::collection($order->items),
        ];
    }
}
