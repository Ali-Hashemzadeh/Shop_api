<?php

declare(strict_types=1);

namespace Modules\Sms\Domain\Contracts;

use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * A concrete SMS gateway. Implementations translate the internal SmsMessageDTO
 * into their own API format (endpoint, field names, template id lookup, phone
 * number format) and report the outcome as an SmsResultDTO.
 *
 * Nothing outside the Sms module depends on this interface — callers use
 * SmsManagerInterface, which selects the configured provider.
 */
interface SmsProviderInterface
{
    /** Stable provider name, matching its key in config('sms.providers'). */
    public function name(): string;

    public function send(SmsMessageDTO $message): SmsResultDTO;
}
