<?php

namespace Modules\Shipment\Infrastructure\Providers;

use App\Console\Commands\ShipmentGenerateDeliverySlotsCommand;
use Illuminate\Support\ServiceProvider;
use Modules\Shipment\Domain\Contracts\LocalDeliveryEligibilityInterface;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\Contracts\ShippingCostCalculatorInterface;
use Modules\Shipment\Infrastructure\Persistence\Repositories\EloquentShipmentManager;
use Modules\Shipment\Infrastructure\Services\ConfigLocalDeliveryEligibility;
use Modules\Shipment\Infrastructure\Shipping\Post\PostShippingCalculator;
use Modules\Shipment\Infrastructure\Validation\ShipmentTicketReferenceValidator;

class ShipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/shipment.php', 'shipment');
        $this->mergeConfigFrom(__DIR__.'/../../../../config/shipping.php', 'shipping');

        $this->app->bind(LocalDeliveryEligibilityInterface::class, ConfigLocalDeliveryEligibility::class);
        $this->app->bind(ShipmentManagerInterface::class, EloquentShipmentManager::class);

        // The Post tariff engine — the sole owner of shipping-cost calculation. Swap
        // this binding to price a different carrier behind the same contract.
        $this->app->bind(ShippingCostCalculatorInterface::class, PostShippingCalculator::class);

        // Ticket ownership-checks `shipment` references via our own table (tag).
        $this->app->tag(ShipmentTicketReferenceValidator::class, 'ticket.reference_validators');
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
