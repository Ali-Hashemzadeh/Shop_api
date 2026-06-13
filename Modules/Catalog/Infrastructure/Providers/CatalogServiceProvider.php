<?php

namespace Modules\Catalog\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Http\Middleware\RequireAdminRole;
use Modules\Catalog\Infrastructure\Persistence\Repositories\EloquentCatalogManager;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogManagerInterface::class, EloquentCatalogManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Persistence/Migrations');

        $this->app['router']->aliasMiddleware('catalog.admin', RequireAdminRole::class);
    }
}
