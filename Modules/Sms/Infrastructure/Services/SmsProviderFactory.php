<?php

declare(strict_types=1);

namespace Modules\Sms\Infrastructure\Services;

use Modules\Sms\Domain\Contracts\SmsProviderInterface;
use Modules\Sms\Domain\Exceptions\UnknownSmsProviderException;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Modules\Sms\Infrastructure\Drivers\LogSmsProvider;
use Modules\Sms\Infrastructure\Drivers\SmsIrProvider;

/**
 * Maps a provider name from config to its concrete driver. Mirrors the Payment
 * module's PaymentGatewayFactory: additional providers register here (or at
 * runtime via register()) without touching any caller.
 */
class SmsProviderFactory
{
    /** @var array<string, class-string<SmsProviderInterface>> */
    private array $providers = [
        'smsir' => SmsIrProvider::class,
        'log' => LogSmsProvider::class,
        'fake' => FakeSmsProvider::class,
    ];

    public function make(string $provider): SmsProviderInterface
    {
        if (! isset($this->providers[$provider])) {
            throw UnknownSmsProviderException::for($provider);
        }

        return app($this->providers[$provider]);
    }

    /** @param  class-string<SmsProviderInterface>  $providerClass */
    public function register(string $name, string $providerClass): void
    {
        $this->providers[$name] = $providerClass;
    }
}
