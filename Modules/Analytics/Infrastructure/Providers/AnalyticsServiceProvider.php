<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Analytics\Application\Listeners\RecordBlogEngagementAnalytics;
use Modules\Analytics\Application\Listeners\RecordOrderCancelledAnalytics;
use Modules\Analytics\Application\Listeners\RecordOrderSaleAnalytics;
use Modules\Analytics\Application\Listeners\RecordPaymentAnalytics;
use Modules\Analytics\Application\Listeners\RecordShipmentAnalytics;
use Modules\Analytics\Domain\Contracts\AnalyticsManagerInterface;
use Modules\Analytics\Infrastructure\Persistence\Repositories\EloquentAnalyticsManager;
use Modules\Blog\Domain\Events\BlogCommentApprovedEvent;
use Modules\Blog\Domain\Events\BlogPostBookmarkedEvent;
use Modules\Blog\Domain\Events\BlogPostLikedEvent;
use Modules\Blog\Domain\Events\BlogPostViewedEvent;
use Modules\Blog\Domain\Events\BlogProductClickedEvent;
use Modules\Order\Domain\Events\OrderCancelledEvent;
use Modules\Order\Domain\Events\OrderPaidEvent;
use Modules\Payment\Domain\Events\PaymentCancelledEvent;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;
use Modules\Shipment\Domain\Events\ShipmentAssignedToDeliveryEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveredEvent;
use Modules\Shipment\Domain\Events\ShipmentDeliveryFailedEvent;
use Modules\Shipment\Domain\Events\ShipmentHandedToPostEvent;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnalyticsManagerInterface::class, EloquentAnalyticsManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        // Order events
        Event::listen(OrderPaidEvent::class, RecordOrderSaleAnalytics::class);
        Event::listen(OrderCancelledEvent::class, RecordOrderCancelledAnalytics::class);

        // Payment events
        Event::listen(PaymentSuccessfulEvent::class, [RecordPaymentAnalytics::class, 'handleSuccess']);
        Event::listen(PaymentFailedEvent::class, [RecordPaymentAnalytics::class, 'handleFailed']);
        Event::listen(PaymentCancelledEvent::class, [RecordPaymentAnalytics::class, 'handleCancelled']);

        // Shipment events
        Event::listen(ShipmentAssignedToDeliveryEvent::class, [RecordShipmentAnalytics::class, 'handleAssigned']);
        Event::listen(ShipmentHandedToPostEvent::class, [RecordShipmentAnalytics::class, 'handleHandedToPost']);
        Event::listen(ShipmentDeliveredEvent::class, [RecordShipmentAnalytics::class, 'handleDelivered']);
        Event::listen(ShipmentDeliveryFailedEvent::class, [RecordShipmentAnalytics::class, 'handleFailed']);

        // Blog engagement events (primitives only; Analytics never reads Blog tables)
        Event::listen(BlogPostViewedEvent::class, [RecordBlogEngagementAnalytics::class, 'handleViewed']);
        Event::listen(BlogPostLikedEvent::class, [RecordBlogEngagementAnalytics::class, 'handleLiked']);
        Event::listen(BlogPostBookmarkedEvent::class, [RecordBlogEngagementAnalytics::class, 'handleBookmarked']);
        Event::listen(BlogCommentApprovedEvent::class, [RecordBlogEngagementAnalytics::class, 'handleCommentApproved']);
        Event::listen(BlogProductClickedEvent::class, [RecordBlogEngagementAnalytics::class, 'handleProductClicked']);
    }
}
