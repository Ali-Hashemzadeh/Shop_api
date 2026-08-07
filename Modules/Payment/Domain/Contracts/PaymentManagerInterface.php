<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Contracts;

use Modules\Payment\Domain\DTOs\PaymentDTO;

interface PaymentManagerInterface
{
    public function initializePayment(int $orderId, int $userId, string $methodType, ?string $gateway = null): array;

    /**
     * Every payment attempt for an order, newest first.
     *
     * @return list<PaymentDTO>
     */
    public function getForOrder(int $orderId): array;
}
