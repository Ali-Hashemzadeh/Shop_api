<?php

namespace Modules\Promotion\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Infrastructure\Persistence\Repositories\EloquentPromotionManager;

class PromotionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromotionManagerInterface::class, EloquentPromotionManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
