<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Payment\Domain\DTOs\PaymentDTO;

/** @mixin PaymentDTO */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PaymentDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'order_id' => $dto->orderId,
            'method_type' => $dto->methodType->value,
            'gateway' => $dto->gateway,
            'status' => $dto->status->value,
            'amount' => $dto->amount,
            'transaction_reference' => $dto->transactionReference,
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
