<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Gateways;

use Illuminate\Support\Facades\Http;
use Modules\Payment\Domain\Contracts\PaymentGatewayDriverInterface;
use Modules\Payment\Domain\DTOs\GatewayRedirectDTO;
use RuntimeException;

class ZarinpalGatewayDriver implements PaymentGatewayDriverInterface
{
    private const PRODUCTION_REQUEST_URL = 'https://payment.zarinpal.com/pg/v4/payment/request.json';

    private const PRODUCTION_VERIFY_URL = 'https://payment.zarinpal.com/pg/v4/payment/verify.json';

    private const SANDBOX_REQUEST_URL = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';

    private const SANDBOX_VERIFY_URL = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';

    private const START_PAY_URL = 'https://www.zarinpal.com/pg/StartPay/';

    private bool $sandbox;

    public function __construct()
    {
        $this->sandbox = (bool) config('payment.gateways.zarinpal.sandbox', false);
    }

    private function requestUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_REQUEST_URL : self::PRODUCTION_REQUEST_URL;
    }

    private function verifyUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_VERIFY_URL : self::PRODUCTION_VERIFY_URL;
    }

    public function requestPayment(
        int $orderId,
        int $amountInCents,
        string $callbackUrl,
        ?array $customerMetadata = [],
    ): GatewayRedirectDTO {
        $response = Http::post($this->requestUrl(), [
            'merchant_id' => config('payment.gateways.zarinpal.merchant_id'),
            'amount' => $amountInCents,
            'callback_url' => $callbackUrl,
            'description' => "Payment for order #{$orderId}",
            'metadata' => $customerMetadata ?? [],
        ]);

        $data = $response->json();

        if (($data['data']['code'] ?? null) !== 100) {
            throw new RuntimeException('Zarinpal payment request failed: '.json_encode($data['errors'] ?? $data));
        }

        $authority = $data['data']['authority'];

        return new GatewayRedirectDTO(
            redirectUrl: self::START_PAY_URL.$authority,
            transactionReference: $authority,
        );
    }

    public function verifyPayment(int $amountInCents, string $authority): array
    {
        $response = Http::post($this->verifyUrl(), [
            'merchant_id' => config('payment.gateways.zarinpal.merchant_id'),
            'amount' => $amountInCents,
            'authority' => $authority,
        ]);

        $data = $response->json();
        $code = $data['data']['code'] ?? null;

        $success = in_array($code, [100, 101], true);

        return [
            'success' => $success,
            'reference_id' => $success ? (string) ($data['data']['ref_id'] ?? '') : null,
            'raw_response' => $data,
        ];
    }
}
