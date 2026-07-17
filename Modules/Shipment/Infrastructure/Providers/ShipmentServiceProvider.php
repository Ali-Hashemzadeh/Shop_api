<?php

namespace Modules\Shipment\Infrastructure\Providers;

use App\Console\Commands\ShipmentGenerateDeliverySlotsCommand;
use Illuminate\Support\ServiceProvider;
use Modules\Shipment\Domain\Contracts\LocalDeliveryEligibilityInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Infrastructure\Persistence\Repositories\EloquentShipmentManager;
use Modules\Shipment\Infrastructure\Services\ConfigLocalDeliveryEligibility;

class ShipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/shipment.php', 'shipment');

        $this->app->bind(LocalDeliveryEligibilityInterface::class, ConfigLocalDeliveryEligibility::class);
        $this->app->bind(ShipmentManagerInterface::class, EloquentShipmentManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        $this->commands([
            ShipmentGenerateDeliverySlotsCommand::class,
        ]);
    }
}
