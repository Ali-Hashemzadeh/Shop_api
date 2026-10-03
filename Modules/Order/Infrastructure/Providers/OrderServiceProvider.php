<?php

namespace Modules\Order\Infrastructure\Providers;

use App\Console\Commands\OrdersCancelExpiredCommand;
use App\Console\Commands\OrdersSyncSalesCountsCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Policies\OrderPolicy;
use Modules\Order\Infrastructure\Persistence\Repositories\EloquentOrderManager;
use Modules\Order\Infrastructure\Validation\OrderTicketReferenceValidator;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderManagerInterface::class, EloquentOrderManager::class);

        // Let the Ticket module ownership-check `order` references against our own
        // table, without Ticket importing the Order model (published contract + tag).
        $this->app->tag(OrderTicketReferenceValidator::class, 'ticket.reference_validators');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
        Gate::policy(Order::class, OrderPolicy::class);
        $this->commands([
            OrdersCancelExpiredCommand::class,
            OrdersSyncSalesCountsCommand::class,
        ]);
    }
}
