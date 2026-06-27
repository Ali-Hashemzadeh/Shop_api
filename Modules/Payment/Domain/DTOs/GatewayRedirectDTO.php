<?php

declare(strict_types=1);

namespace Modules\Payment\Domain\DTOs;

class GatewayRedirectDTO
{
    public function __construct(
        public readonly string $redirectUrl,
        public readonly string $transactionReference,
    ) {}
}
