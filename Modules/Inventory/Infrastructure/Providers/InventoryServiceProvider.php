<?php

namespace Modules\Inventory\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Infrastructure\Persistence\Repositories\EloquentInventoryManager;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryManagerInterface::class, EloquentInventoryManager::class);
        $this->app->register(InventoryAuthServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
