<?php

declare(strict_types=1);

namespace Modules\Sms\Infrastructure\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sms\Domain\Contracts\SmsManagerInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;
use Throwable;

/**
 * Resolves the configured provider and delegates delivery to it. Any provider
 * failure — a misconfigured provider name, a transport exception, a rejected
 * message — is converted into a failed SmsResultDTO so the caller's business
 * flow never breaks because SMS was unavailable.
 */
class SmsManager implements SmsManagerInterface
{
    public function __construct(
        private readonly SmsProviderFactory $factory,
    ) {}

    public function providerName(): string
    {
        return (string) config('sms.default', 'log');
    }

    public function send(SmsMessageDTO $message): SmsResultDTO
    {
        $provider = $this->providerName();

        try {
            return $this->factory->make($provider)->send($message);
        } catch (Throwable $e) {
            Log::error('[SMS] provider threw while sending', [
                'provider' => $provider,
                'template' => $message->template,
                'error' => $e->getMessage(),
            ]);

            return SmsResultDTO::failure($provider, $e->getMessage());
        }
    }
}
