<?php

namespace Modules\Identity\Infrastructure\Services;

use Illuminate\Support\Facades\Log;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;

/**
 * Placeholder OTP delivery driver.
 *
 * Until the SMS web service is connected, the generated code is written to the
 * application log so the flow remains fully usable in development. Swap the
 * binding in IdentityServiceProvider for a real gateway implementation later.
 */
class LogOtpSender implements OtpSenderInterface
{
    public function send(string $phone, string $code): void
    {
        Log::info('OTP dispatched', [
            'phone' => $phone,
            'code' => $code,
        ]);
    }
}
