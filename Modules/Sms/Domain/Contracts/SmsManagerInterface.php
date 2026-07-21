<?php

declare(strict_types=1);

namespace Modules\Sms\Domain\Contracts;

use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * The Sms module's only public entry point. Callers hand over a
 * provider-independent SmsMessageDTO; which provider actually delivers it is a
 * configuration detail they never see.
 */
interface SmsManagerInterface
{
    public function send(SmsMessageDTO $message): SmsResultDTO;

    /** Name of the currently configured provider (for delivery bookkeeping). */
    public function providerName(): string;
}
