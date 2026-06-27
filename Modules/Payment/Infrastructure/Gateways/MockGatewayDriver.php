<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Gateways;

use Modules\Payment\Domain\Contracts\PaymentGatewayDriverInterface;
use Modules\Payment\Domain\DTOs\GatewayRedirectDTO;

class MockGatewayDriver implements PaymentGatewayDriverInterface
{
    public bool $shouldVerifySucceed = true;

    public string $fakeAuthority = 'MOCK-AUTHORITY-0000000001';

    public string $fakeRefId = 'MOCK-REF-12345';

    public function requestPayment(
        int $orderId,
        int $amountInCents,
        string $callbackUrl,
        ?array $customerMetadata = [],
    ): GatewayRedirectDTO {
        return new GatewayRedirectDTO(
            redirectUrl: 'https://mock-gateway.test/pay/'.$this->fakeAuthority,
            transactionReference: $this->fakeAuthority,
        );
    }

    public function verifyPayment(int $amountInCents, string $authority): array
    {
        if (! $this->shouldVerifySucceed) {
            return [
                'success' => false,
                'reference_id' => null,
                'raw_response' => ['data' => ['code' => -22], 'errors' => []],
            ];
        }

        return [
            'success' => true,
            'reference_id' => $this->fakeRefId,
            'raw_response' => ['data' => ['code' => 100, 'ref_id' => $this->fakeRefId], 'errors' => []],
        ];
    }
}
