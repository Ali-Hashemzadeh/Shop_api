<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\Contracts;

use Modules\Payment\Domain\DTOs\GatewayRedirectDTO;

interface PaymentGatewayDriverInterface
{
    public function requestPayment(
        int $orderId,
        int $amountInCents,
        string $callbackUrl,
        ?array $customerMetadata = [],
    ): GatewayRedirectDTO;

    /**
     * @return array{success: bool, reference_id: string|null, raw_response: array}
     */
    public function verifyPayment(int $amountInCents, string $authority): array;
}
