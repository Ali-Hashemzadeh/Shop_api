<?php

namespace Modules\Identity\Infrastructure\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Policies\AddressPolicy;
use Modules\Identity\Domain\Policies\ProfilePolicy;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;
use Modules\Identity\Infrastructure\Persistence\Repositories\EloquentAddressRepository;
use Modules\Identity\Infrastructure\Persistence\Repositories\EloquentIdentityManager;
use Modules\Identity\Infrastructure\Persistence\Repositories\EloquentUserRepository;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use Modules\Identity\Infrastructure\Services\LogOtpSender;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityManagerInterface::class, EloquentIdentityManager::class);
        $this->app->bind(
            UserRepositoryInterface::class,
            EloquentUserRepository::class);
        $this->app->bind(
            AddressRepositoryInterface::class,
            EloquentAddressRepository::class);
        // Log-only placeholder until the SMS web service is wired in.
        $this->app->bind(OtpSenderInterface::class, LogOtpSender::class);
        $this->loadMigrationsFrom(
            base_path(
                'Modules/Identity/Infrastructure/Persistence/Migrations'
            )
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        Gate::policy(Address::class, AddressPolicy::class);
        Gate::policy(User::class, ProfilePolicy::class);
    }
}
