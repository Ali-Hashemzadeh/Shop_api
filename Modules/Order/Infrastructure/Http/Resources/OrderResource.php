<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Domain\DTOs\OrderDTO;

/** @mixin OrderDTO */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'status' => $dto->status->value,
            'total_amount' => $dto->totalAmount,
            'shipping_cost' => $dto->shippingCost,
            'tax_amount' => $dto->taxAmount,
            'shipment_method_id' => $dto->shipmentMethodId,
            'shipment_method_code' => $dto->shipmentMethodCode,
            'shipping_address' => $dto->shippingAddress,
            'shipment_snapshot' => $dto->shipmentSnapshot,
            'transaction_ref' => $dto->transactionRef,
            'notes' => $dto->notes,
            'created_at' => $dto->createdAt->toISOString(),
            'items' => OrderItemResource::collection($dto->items),
        ];
    }
}
