<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Persistence\Repositories;

use Modules\Payment\Application\Actions\InitializePaymentAction;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;
use Modules\Payment\Domain\DTOs\PaymentDTO;
use Modules\Payment\Domain\Models\Payment;

class EloquentPaymentManager implements PaymentManagerInterface
{
    public function initializePayment(int $orderId, int $userId, string $methodType, ?string $gateway = null): array
    {
        return app(InitializePaymentAction::class)->handle($orderId, $userId, $methodType, $gateway);
    }

    public function getForOrder(int $orderId): array
    {
        return Payment::where('order_id', $orderId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Payment $payment): PaymentDTO => PaymentDTO::fromModel($payment))
            ->all();
    }
}
