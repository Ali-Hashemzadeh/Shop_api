<?php

use App\Providers\AppServiceProvider;
use Modules\Cart\Infrastructure\Providers\CartServiceProvider;
use Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider;
use Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use Modules\Inventory\Infrastructure\Providers\InventoryServiceProvider;
use Modules\Media\Infrastructure\Providers\MediaServiceProvider;
use Modules\Order\Infrastructure\Providers\OrderServiceProvider;
use Modules\Payment\Infrastructure\Providers\PaymentServiceProvider;
use Modules\Shipment\Infrastructure\Providers\ShipmentServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    MediaServiceProvider::class,
    InventoryServiceProvider::class,
    CartServiceProvider::class,
    OrderServiceProvider::class,
    PaymentServiceProvider::class,
    ShipmentServiceProvider::class,
];
