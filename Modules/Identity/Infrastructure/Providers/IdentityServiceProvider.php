<?php

namespace Modules\Identity\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;
use Modules\Identity\Infrastructure\Persistence\Repositories\EloquentAddressRepository;
use Modules\Identity\Infrastructure\Persistence\Repositories\EloquentUserRepository;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(AddressRepositoryInterface::class, EloquentAddressRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__ . '/../Persistence/Migrations');
    }
}
