<?php

declare(strict_types=1);

namespace Modules\Sms\Domain\DTOs;

/**
 * Provider-independent SMS message.
 *
 * This shape is the stable internal contract: callers always describe *what*
 * they want sent (an internal template name plus business parameters), never
 * how a particular provider's API expects it. Translating this DTO into a
 * concrete HTTP payload is the sole responsibility of an SmsProviderInterface
 * implementation.
 *
 * `receiver` is the canonical local Iranian mobile format (09XXXXXXXXX).
 * Providers convert it to whatever format they require (SMS.ir wants
 * 98XXXXXXXXX, for example).
 *
 * `template` is one of our own template names (e.g. `payment_success`) — the
 * per-provider template id is resolved from config by the provider.
 *
 * `parameters` keys are business parameter names owned by our system (e.g.
 * `OrderId`). They are identical across every provider.
 */
class SmsMessageDTO
{
    /**
     * @param  array<string, scalar|null>  $parameters
     */
    public function __construct(
        public readonly string $receiver,
        public readonly string $template,
        public readonly array $parameters = [],
    ) {}
}
