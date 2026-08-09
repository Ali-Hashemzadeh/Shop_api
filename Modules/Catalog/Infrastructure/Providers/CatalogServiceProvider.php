<?php

namespace Modules\Catalog\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Services\CategoryHierarchy;
use Modules\Catalog\Infrastructure\Persistence\Repositories\EloquentCatalogManager;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Caching is intentionally not implemented yet — bind the interface
        // straight to the Eloquent manager. A cache decorator can be layered
        // back in later without touching callers.
        $this->app->bind(CatalogManagerInterface::class, EloquentCatalogManager::class);

        // Scoped, not singleton: the category tree is memoized for the life of one
        // request so a product listing resolves it once, but never leaks across
        // requests (or across queued jobs) where an admin's edit would go unseen.
        $this->app->scoped(CategoryHierarchy::class);

        $this->app->register(CatalogAuthServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
