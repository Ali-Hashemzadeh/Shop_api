<?php

namespace Modules\Identity\Domain\Contracts;

/**
 * Delivery boundary for one-time passcodes.
 *
 * Implementations are swappable: today a log-only driver stands in for the SMS
 * gateway; a real provider can be bound in its place without touching the
 * authentication flow.
 */
interface OtpSenderInterface
{
    /**
     * Deliver a one-time passcode to the given phone number.
     */
    public function send(string $phone, string $code): void;
}
