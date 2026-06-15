<?php

namespace Modules\Catalog\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Persistence\Repositories\EloquentCatalogManager;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogManagerInterface::class, EloquentCatalogManager::class);
        $this->app->register(CatalogAuthServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Persistence/Migrations');
    }
}
