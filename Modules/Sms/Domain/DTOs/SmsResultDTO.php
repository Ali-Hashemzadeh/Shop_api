<?php

declare(strict_types=1);

namespace Modules\Sms\Domain\DTOs;

/**
 * Outcome of a single send attempt. SMS delivery is best-effort and never
 * mandatory: both a failure and a skip are reported as data (never as an
 * exception escaping the manager) so a business flow is never broken by an
 * unreachable — or simply unconfigured — SMS provider.
 *
 * Three outcomes, deliberately distinct:
 *   success — the provider accepted the message
 *   skipped — nothing was attempted because the channel is not configured for
 *             this template (no credentials, no provider template id). Expected
 *             in dev and for templates a shop has not set up; `reason` explains it.
 *   failure — a real attempt was made and did not succeed (transport error,
 *             provider rejection). Worth alerting on.
 */
class SmsResultDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $provider,
        public readonly ?string $reference = null,
        public readonly ?string $error = null,
        public readonly bool $skipped = false,
    ) {}

    public static function success(string $provider, ?string $reference = null): self
    {
        return new self(success: true, provider: $provider, reference: $reference);
    }

    public static function failure(string $provider, string $error): self
    {
        return new self(success: false, provider: $provider, error: $error);
    }

    /** Nothing was sent, and that is an acceptable, expected outcome. */
    public static function skipped(string $provider, string $reason): self
    {
        return new self(success: false, provider: $provider, error: $reason, skipped: true);
    }
}
