<?php

declare(strict_types=1);

namespace Modules\Sms\Infrastructure\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Sms\Domain\Contracts\SmsProviderInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * SMS.ir template-based delivery.
 *
 * Translation happens entirely here: our internal template name becomes an
 * SMS.ir `templateId`, our `parameters` map becomes SMS.ir's list of
 * `{name, value}` pairs, and the canonical 09XXXXXXXXX receiver becomes
 * 98XXXXXXXXX. Callers never see any of this.
 *
 * Note: this is deliberately separate from Identity's SmsIrOtpSender. OTP
 * delivery and notification delivery are different responsibilities with
 * different contracts, even though they happen to hit the same vendor API.
 */
class SmsIrProvider implements SmsProviderInterface
{
    private string $apiKey;

    private string $endpoint;

    public function __construct()
    {
        $this->apiKey = (string) config('sms.providers.smsir.api_key', '');
        $this->endpoint = (string) config('sms.providers.smsir.endpoint', 'https://api.sms.ir/v1/send/verify');
    }

    public function name(): string
    {
        return 'smsir';
    }

    public function send(SmsMessageDTO $message): SmsResultDTO
    {
        // Missing configuration is a skip, not a failure: SMS is optional, so a
        // shop that has not set up credentials or a particular template simply
        // does not send that message. No exception, no failed-delivery noise.
        if ($this->apiKey === '') {
            return $this->skip($message, 'SMS.ir api key is not configured.');
        }

        $templateId = config("sms.providers.smsir.templates.{$message->template}");

        if ($templateId === null || $templateId === '') {
            return $this->skip(
                $message,
                "No SMS.ir template id configured for template [{$message->template}]."
            );
        }

        $response = Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->post($this->endpoint, $this->toProviderPayload($message, (int) $templateId));

        if ($response->failed()) {
            return SmsResultDTO::failure(
                $this->name(),
                'SMS.ir delivery failed [HTTP '.$response->status().']: '.$response->body()
            );
        }

        $body = (array) $response->json();

        // SMS.ir signals application-level success with status === 1.
        if ((int) ($body['status'] ?? 0) !== 1) {
            return SmsResultDTO::failure(
                $this->name(),
                'SMS.ir rejected the message: '.(string) ($body['message'] ?? 'unknown error')
            );
        }

        $messageId = $body['data']['messageId'] ?? null;

        return SmsResultDTO::success(
            $this->name(),
            $messageId !== null ? (string) $messageId : null,
        );
    }

    private function skip(SmsMessageDTO $message, string $reason): SmsResultDTO
    {
        Log::info('[SMS] message skipped — provider not configured', [
            'provider' => $this->name(),
            'template' => $message->template,
            'reason' => $reason,
        ]);

        return SmsResultDTO::skipped($this->name(), $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function toProviderPayload(SmsMessageDTO $message, int $templateId): array
    {
        $parameters = [];

        foreach ($message->parameters as $name => $value) {
            $parameters[] = ['name' => (string) $name, 'value' => (string) $value];
        }

        return [
            'mobile' => $this->toInternationalFormat($message->receiver),
            'templateId' => $templateId,
            'parameters' => $parameters,
        ];
    }

    // Converts 09XXXXXXXXX (local Iranian format) to 989XXXXXXXXX (E.164 without +)
    private function toInternationalFormat(string $phone): string
    {
        return str_starts_with($phone, '0') ? '98'.substr($phone, 1) : $phone;
    }
}
