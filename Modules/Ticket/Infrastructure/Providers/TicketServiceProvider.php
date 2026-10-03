<?php

namespace Modules\Ticket\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ticket\Domain\Contracts\TicketManagerInterface;
use Modules\Ticket\Infrastructure\Persistence\Repositories\EloquentTicketManager;

class TicketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TicketManagerInterface::class, EloquentTicketManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
