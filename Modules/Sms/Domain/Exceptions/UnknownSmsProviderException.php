<?php

declare(strict_types=1);

namespace Modules\Sms\Domain\Exceptions;

use RuntimeException;

class UnknownSmsProviderException extends RuntimeException
{
    public static function for(string $provider): self
    {
        return new self("Unknown SMS provider: [{$provider}].");
    }
}
