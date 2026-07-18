<?php

declare(strict_types=1);

namespace Modules\Order\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Order\Domain\DTOs\OrderDTO;

/**
 * Lightweight admin list row. The customer summary is read from the order's frozen
 * customer_snapshot — never re-queried from Identity per order.
 *
 * @mixin OrderDTO
 */
class AdminOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderDTO $dto */
        $dto = $this->resource;
        $customer = $dto->customerSnapshot ?? [];

        return [
            'id' => $dto->id,
            'status' => $dto->status->value,
            'total_amount' => $dto->totalAmount,
            'created_at' => $dto->createdAt->toISOString(),
            'customer' => [
                'name' => $customer['name'] ?? null,
                'last_name' => $customer['last_name'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            'item_count' => count($dto->items),
        ];
    }
}
