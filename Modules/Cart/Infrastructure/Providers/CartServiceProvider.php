<?php

namespace Modules\Cart\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Cart\Infrastructure\Http\Middleware\CartIdentificationMiddleware;
use Modules\Cart\Infrastructure\Persistence\Repositories\EloquentCartManager;

class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CartManagerInterface::class, EloquentCartManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        $this->app['router']->aliasMiddleware('cart.identify', CartIdentificationMiddleware::class);
    }
}
