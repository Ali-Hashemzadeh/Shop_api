<?php

use App\Providers\AppServiceProvider;
use Modules\Analytics\Infrastructure\Providers\AnalyticsServiceProvider;
use Modules\Cart\Infrastructure\Providers\CartServiceProvider;
use Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider;
use Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use Modules\Inventory\Infrastructure\Providers\InventoryServiceProvider;
use Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use Modules\Notification\Infrastructure\Providers\NotificationServiceProvider;
use Modules\Order\Infrastructure\Providers\OrderServiceProvider;
use Modules\Payment\Infrastructure\Providers\PaymentServiceProvider;
use Modules\Promotion\Infrastructure\Providers\PromotionServiceProvider;
use Modules\Review\Infrastructure\Providers\ReviewServiceProvider;
use Modules\Shipment\Infrastructure\Providers\ShipmentServiceProvider;
use Modules\Sms\Infrastructure\Providers\SmsServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    // Promotion is a leaf dependency (it imports no other business module), and
    // Catalog resolves it for live pricing — so it registers first.
    PromotionServiceProvider::class,
    CatalogServiceProvider::class,
    MediaServiceProvider::class,
    InventoryServiceProvider::class,
    CartServiceProvider::class,
    OrderServiceProvider::class,
    PaymentServiceProvider::class,
    ShipmentServiceProvider::class,
    SmsServiceProvider::class,
    NotificationServiceProvider::class,
    AnalyticsServiceProvider::class,
    ReviewServiceProvider::class,
];
