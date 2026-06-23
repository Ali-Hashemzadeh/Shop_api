<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Services;

use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;

class SmsIrOtpSender implements OtpSenderInterface
{
    private const ENDPOINT = 'https://api.sms.ir/v1/send/verify';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $templateId,
        private readonly string $codeParam,
    ) {}

    public function send(string $phone, string $code): void
    {
        $response = Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->post(self::ENDPOINT, [
                'mobile' => $this->toInternationalFormat($phone),
                'templateId' => $this->templateId,
                'parameters' => [
                    ['name' => $this->codeParam, 'value' => $code],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'SMS.ir delivery failed [HTTP '.$response->status().']: '.$response->body()
            );
        }
    }

    // Converts 09XXXXXXXXX (local Iranian format) to 989XXXXXXXXX (E.164 without +)
    private function toInternationalFormat(string $phone): string
    {
        return '98'.substr($phone, 1);
    }
}
