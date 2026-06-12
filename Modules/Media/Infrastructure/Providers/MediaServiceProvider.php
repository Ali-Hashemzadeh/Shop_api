<?php

namespace Modules\Media\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Infrastructure\Persistence\Repositories\LocalMediaManager;

class MediaServiceProvider extends ServiceProvider
{
    /**
     * Register any module services.
     */
    public function register(): void
    {
        // Bind our cross-module contract interface to this local implementation
        $this->app->bind(
            MediaManagerInterface::class,
            LocalMediaManager::class
        );
    }

    /**
     * Bootstrap any module services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Persistence/Migrations');
    }
}
