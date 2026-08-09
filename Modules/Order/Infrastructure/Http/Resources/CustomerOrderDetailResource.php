<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Domain\DTOs\CustomerOrderDetailDTO;
use Modules\Payment\Domain\DTOs\PaymentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentDTO;
use Modules\Shipment\Domain\DTOs\ShipmentStatusHistoryDTO;

/** @mixin CustomerOrderDetailDTO */
class CustomerOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var CustomerOrderDetailDTO $detail */
        $detail = $this->resource;
        $order = $detail->order;

        return [
            'id' => $order->id,
            'public_code' => $order->publicCode,
            'status' => $order->status->value,
            'total_amount' => $order->totalAmount,
            'shipping_cost' => $order->shippingCost,
            'tax_amount' => $order->taxAmount,
            'coupon_code' => $order->couponCode,
            'coupon_discount_amount' => $order->couponDiscountAmount,
            'coupon_snapshot' => $order->couponSnapshot,
            'payment_pricing_finalized_at' => $order->paymentPricingFinalizedAt?->toISOString(),
            'shipment_method_id' => $order->shipmentMethodId,
            'shipment_method_code' => $order->shipmentMethodCode,
            'shipping_address' => $order->shippingAddress,
            'shipment_snapshot' => $order->shipmentSnapshot,
            'customer_snapshot' => $order->customerSnapshot,
            'transaction_ref' => $order->transactionRef,
            'notes' => $order->notes,
            'created_at' => $order->createdAt->toISOString(),
            'items' => OrderItemResource::collection($order->items),
            'payments' => array_map($this->payment(...), $detail->payments),
            'shipment' => $detail->shipment === null
                ? null
                : $this->shipment($detail->shipment),
        ];
    }

    /**
     * Keep this field allowlist aligned with PaymentResource. In particular,
     * PaymentDTO::gatewayResponse is intentionally not customer-facing.
     *
     * @return array<string, mixed>
     */
    private function payment(PaymentDTO $payment): array
    {
        return [
            'id' => $payment->id,
            'public_code' => $payment->publicCode,
            'order_id' => $payment->orderId,
            'method_type' => $payment->methodType->value,
            'gateway' => $payment->gateway,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'transaction_reference' => $payment->transactionReference,
            'created_at' => $payment->createdAt->toISOString(),
        ];
    }

    /**
     * Keep this field allowlist aligned with ShipmentResource. The public code
     * remains the API `id`; the Shipment model's numeric id is not exposed.
     *
     * @return array<string, mixed>
     */
    private function shipment(ShipmentDTO $shipment): array
    {
        return [
            'id' => $shipment->publicCode,
            'order_id' => $shipment->orderId,
            'method_code' => $shipment->methodCode,
            'method_title' => $shipment->methodTitle,
            'method_type' => $shipment->methodType,
            'shipping_cost' => $shipment->shippingCost,
            'status' => $shipment->status->value,
            'status_label' => $shipment->status->label(),
            'address' => $shipment->addressSnapshot,
            'delivery_slot' => $shipment->deliverySlotSnapshot,
            'pickup_location' => $shipment->pickupLocationSnapshot,
            'carrier_name' => $shipment->carrierName,
            'tracking_number' => $shipment->trackingNumber,
            'receiver_name' => $shipment->receiverName,
            'failure_reason' => $shipment->failureReason,
            'note' => $shipment->note,
            'handed_to_post_at' => $shipment->handedToPostAt?->toISOString(),
            'out_for_delivery_at' => $shipment->outForDeliveryAt?->toISOString(),
            'delivered_at' => $shipment->deliveredAt?->toISOString(),
            'ready_for_pickup_at' => $shipment->readyForPickupAt?->toISOString(),
            'picked_up_at' => $shipment->pickedUpAt?->toISOString(),
            'created_at' => $shipment->createdAt->toISOString(),
            'history' => array_map($this->history(...), $shipment->history),
        ];
    }

    /** @return array<string, mixed> */
    private function history(ShipmentStatusHistoryDTO $history): array
    {
        return [
            'from_status' => $history->fromStatus,
            'to_status' => $history->toStatus,
            'reason' => $history->reason,
            'note' => $history->note,
            'metadata' => $history->metadata,
            'created_at' => $history->createdAt->toISOString(),
        ];
    }
}
