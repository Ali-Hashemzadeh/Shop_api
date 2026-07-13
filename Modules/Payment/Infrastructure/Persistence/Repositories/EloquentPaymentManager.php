<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Persistence\Repositories;

use Modules\Payment\Application\Actions\InitializePaymentAction;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;

class EloquentPaymentManager implements PaymentManagerInterface
{
    public function initializePayment(int $orderId, int $userId, string $methodType, ?string $gateway = null): array
    {
        return app(InitializePaymentAction::class)->handle($orderId, $userId, $methodType, $gateway);
    }
}
