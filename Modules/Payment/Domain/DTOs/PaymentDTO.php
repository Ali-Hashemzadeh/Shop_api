<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\DTOs;

use Carbon\Carbon;
use Modules\Payment\Domain\Enums\PaymentMethodType;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Models\Payment;

class PaymentDTO
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly PaymentMethodType $methodType,
        public readonly ?string $gateway,
        public readonly ?string $transactionReference,
        public readonly int $amount,
        public readonly PaymentStatus $status,
        public readonly ?array $gatewayResponse,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(Payment $payment): self
    {
        return new self(
            id: $payment->id,
            orderId: $payment->order_id,
            methodType: PaymentMethodType::from($payment->method_type),
            gateway: $payment->gateway,
            transactionReference: $payment->transaction_reference,
            amount: $payment->amount,
            status: PaymentStatus::from($payment->status),
            gatewayResponse: $payment->gateway_response,
            createdAt: Carbon::parse($payment->created_at),
        );
    }
}
