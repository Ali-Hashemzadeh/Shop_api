<?php

namespace Modules\Sms\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Sms\Domain\Contracts\SmsManagerInterface;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Modules\Sms\Infrastructure\Services\SmsManager;
use Modules\Sms\Infrastructure\Services\SmsProviderFactory;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/sms.php', 'sms');

        $this->app->singleton(SmsProviderFactory::class);
        $this->app->bind(SmsManagerInterface::class, SmsManager::class);

        // Singleton so a test inspects the very instance the manager used.
        $this->app->singleton(FakeSmsProvider::class);
    }
}
