<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Gateways;

use InvalidArgumentException;
use Modules\Payment\Domain\Contracts\PaymentGatewayDriverInterface;

class PaymentGatewayFactory
{
    /** @var array<string, class-string<PaymentGatewayDriverInterface>> */
    private array $drivers = [
        'zarinpal' => ZarinpalGatewayDriver::class,
        'mock' => MockGatewayDriver::class,
    ];

    public function make(string $gateway): PaymentGatewayDriverInterface
    {
        if (! isset($this->drivers[$gateway])) {
            throw new InvalidArgumentException("Unknown payment gateway driver: [{$gateway}]");
        }

        return app($this->drivers[$gateway]);
    }

    public function register(string $name, string $driverClass): void
    {
        $this->drivers[$name] = $driverClass;
    }
}
