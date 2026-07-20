<?php

declare(strict_types=1);

namespace Modules\Sms\Infrastructure\Drivers;

use Illuminate\Support\Facades\Log;
use Modules\Sms\Domain\Contracts\SmsProviderInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * Development fallback: writes the message to the log instead of sending it.
 * Mirrors Identity's LogOtpSender so local environments need no credentials.
 */
class LogSmsProvider implements SmsProviderInterface
{
    public function name(): string
    {
        return 'log';
    }

    public function send(SmsMessageDTO $message): SmsResultDTO
    {
        Log::info('[SMS] notification message', [
            'receiver' => $message->receiver,
            'template' => $message->template,
            'parameters' => $message->parameters,
        ]);

        return SmsResultDTO::success($this->name());
    }
}
