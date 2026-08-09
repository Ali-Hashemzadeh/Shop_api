<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Contracts;

use Modules\Payment\Domain\DTOs\PaymentDTO;

interface PaymentManagerInterface
{
    /**
     * @param  string|null  $couponCode  Raw code. Payment performs no pricing itself —
     *                                   it hands this to Order, which validates,
     *                                   reserves, and freezes the payable total.
     */
    public function initializePayment(int $orderId, int $userId, string $methodType, ?string $gateway = null, ?string $couponCode = null): array;

    /**
     * Every payment attempt for an order, newest first.
     *
     * @return list<PaymentDTO>
     */
    public function getForOrder(int $orderId): array;
}
