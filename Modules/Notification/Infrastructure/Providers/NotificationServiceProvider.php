<?php

namespace Modules\Notification\Infrastructure\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Notification\Domain\Policies\NotificationPolicy;
use Modules\Notification\Infrastructure\Channels\NotificationChannelFactory;
use Modules\Notification\Infrastructure\Persistence\Repositories\EloquentNotificationManager;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationManagerInterface::class, EloquentNotificationManager::class);

        $this->app->singleton(NotificationChannelFactory::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        Gate::policy(Notification::class, NotificationPolicy::class);
    }
}
