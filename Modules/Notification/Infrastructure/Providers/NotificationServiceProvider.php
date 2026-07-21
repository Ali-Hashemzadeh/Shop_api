<?php

namespace Modules\Notification\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Notification\Application\Listeners\SendOrderCancelledNotifications;
use Modules\Notification\Application\Listeners\SendOrderPaidNotifications;
use Modules\Notification\Application\Listeners\SendPaymentFailedNotification;
use Modules\Notification\Application\Listeners\SendShipmentDeliveredNotifications;
use Modules\Notification\Application\Listeners\SendShipmentPreparingNotification;
use Modules\Notification\Application\Listeners\SendShipmentSentNotifications;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Notification\Domain\Policies\NotificationPolicy;
use Modules\Notification\Infrastructure\Channels\NotificationChannelFactory;
use Modules\Notification\Infrastructure\Persistence\Repositories\EloquentNotificationManager;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentPreparingStartedEvent;
use Modules\Shipment\Domain\Events\ShipmentSentEvent;

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

        $this->registerEventListeners();
    }

    /**
     * Bind the published integration events of the business modules to this
     * module's listeners. This is the only place the two sides meet: business
     * modules dispatch primitives-only events and know nothing about
     * notifications; nothing outside this module calls NotificationManager.
     *
     * Every listener implements ShouldHandleEventsAfterCommit, so a rolled-back
     * business transaction never produces a notification.
     */
    private function registerEventListeners(): void
    {
        Event::listen(OrderPaidEvent::class, SendOrderPaidNotifications::class);
        Event::listen(OrderCancelledEvent::class, SendOrderCancelledNotifications::class);
        Event::listen(PaymentFailedEvent::class, SendPaymentFailedNotification::class);
        Event::listen(ShipmentPreparingStartedEvent::class, SendShipmentPreparingNotification::class);
        Event::listen(ShipmentSentEvent::class, SendShipmentSentNotifications::class);
        Event::listen(ShipmentDeliveredEvent::class, SendShipmentDeliveredNotifications::class);
    }
}
