<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Contracts;

interface PaymentManagerInterface
{
    public function initializePayment(int $orderId, string $methodType, ?string $gateway = null): array;
}
