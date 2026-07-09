<?php

namespace Modules\Catalog\Infrastructure\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Infrastructure\Persistence\Repositories\CachedCatalogManager;
use Modules\Catalog\Infrastructure\Persistence\Repositories\EloquentCatalogManager;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/catalog.php', 'catalog');

        if (config('catalog.cache.enabled')) {
            $this->app->bind(CatalogManagerInterface::class, function ($app) {
                return new CachedCatalogManager(
                    $app->make(EloquentCatalogManager::class),
                    $app->make(CacheRepository::class),
                    (int) config('catalog.cache.ttl', 3600),
                );
            });
        } else {
            $this->app->bind(CatalogManagerInterface::class, EloquentCatalogManager::class);
        }

        $this->app->register(CatalogAuthServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
