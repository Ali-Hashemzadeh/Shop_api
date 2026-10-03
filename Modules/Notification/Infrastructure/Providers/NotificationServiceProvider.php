<?php

namespace Modules\Notification\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Inventory\Domain\Events\InventoryRestockedEvent;
use Modules\Notification\Application\Listeners\SendDeliveryVerificationCodeSms;
use Modules\Notification\Application\Listeners\SendOrderCancelledNotifications;
use Modules\Notification\Application\Listeners\SendOrderPaidNotifications;
use Modules\Notification\Application\Listeners\SendPaymentFailedNotification;
use Modules\Notification\Application\Listeners\SendProductAvailableNotifications;
use Modules\Notification\Application\Listeners\SendShipmentAssignedToDeliveryNotifications;
use Modules\Notification\Application\Listeners\SendShipmentDeliveredNotifications;
use Modules\Notification\Application\Listeners\SendShipmentHandedToPostNotifications;
use Modules\Notification\Application\Listeners\SendShipmentOutForDeliveryNotifications;
use Modules\Notification\Application\Listeners\SendShipmentPreparingNotification;
use Modules\Notification\Application\Listeners\SendShipmentReadyForPickupNotifications;
use Modules\Notification\Application\Listeners\SendTicketAssignedNotifications;
use Modules\Notification\Application\Listeners\SendTicketCreatedNotifications;
use Modules\Notification\Application\Listeners\SendTicketReplyNotifications;
use Modules\Notification\Application\Listeners\SendTicketStatusChangedNotifications;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Notification\Domain\Policies\NotificationPolicy;
use Modules\Notification\Infrastructure\Channels\NotificationChannelFactory;
use Modules\Notification\Infrastructure\Persistence\Repositories\EloquentNotificationManager;
use Modules\Notification\Infrastructure\Persistence\Repositories\EloquentRecipientPreferenceRepository;
use Modules\Notification\Infrastructure\Persistence\Repositories\RecipientPreferenceRepositoryInterface;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Shipment\Domain\Events\DeliveryVerificationCodeIssuedEvent;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;
use Modules\Shipment\Domain\Events\ShipmentOutForDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentPreparingStartedEvent;
use Modules\Shipment\Domain\Events\ShipmentReadyForPickupEvent;
use Modules\Ticket\Domain\Events\TicketAssignedEvent;
use Modules\Ticket\Domain\Events\TicketCreatedEvent;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;
use Modules\Ticket\Domain\Events\TicketStatusChangedEvent;

class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationManagerInterface::class, EloquentNotificationManager::class);
        $this->app->bind(RecipientPreferenceRepositoryInterface::class, EloquentRecipientPreferenceRepository::class);

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
        // One listener per fulfillment shape. The generic "shipment sent" event is
        // gone; the three below replace it with copy that fits each moment.
        Event::listen(ShipmentReadyForPickupEvent::class, SendShipmentReadyForPickupNotifications::class);
        Event::listen(ShipmentHandedToPostEvent::class, SendShipmentHandedToPostNotifications::class);
        Event::listen(ShipmentOutForDeliveryEvent::class, SendShipmentOutForDeliveryNotifications::class);
        Event::listen(ShipmentDeliveredEvent::class, SendShipmentDeliveredNotifications::class);
        Event::listen(ShipmentAssignedToDeliveryEvent::class, SendShipmentAssignedToDeliveryNotifications::class);
        Event::listen(DeliveryVerificationCodeIssuedEvent::class, SendDeliveryVerificationCodeSms::class);
        // Inventory publishes a restock (available 0 → positive); Wishlist owns the
        // subscriptions, Notification delivers to the subscribed customers.
        Event::listen(InventoryRestockedEvent::class, SendProductAvailableNotifications::class);

        // Support tickets. The Ticket module dispatches primitives-only events and
        // knows nothing about notifications; the fan-out to customer/agent lives here.
        Event::listen(TicketCreatedEvent::class, SendTicketCreatedNotifications::class);
        Event::listen(TicketReplyCreatedEvent::class, SendTicketReplyNotifications::class);
        Event::listen(TicketAssignedEvent::class, SendTicketAssignedNotifications::class);
        Event::listen(TicketStatusChangedEvent::class, SendTicketStatusChangedNotifications::class);
    }
}
