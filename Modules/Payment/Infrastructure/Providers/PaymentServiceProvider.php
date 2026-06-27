<?php

namespace Modules\Payment\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;
use Modules\Payment\Infrastructure\Gateways\PaymentGatewayFactory;
use Modules\Payment\Infrastructure\Persistence\Repositories\EloquentPaymentManager;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/payment.php', 'payment');

        $this->app->bind(PaymentManagerInterface::class, EloquentPaymentManager::class);

        $this->app->singleton(PaymentGatewayFactory::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
