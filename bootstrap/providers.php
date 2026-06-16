<?php

use App\Providers\AppServiceProvider;
use Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider;
use Modules\Identity\Infrastructure\Providers\IdentityServiceProvider;
use Modules\Inventory\Infrastructure\Providers\InventoryServiceProvider;
use Modules\Media\Infrastructure\Providers\MediaServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    MediaServiceProvider::class,
    InventoryServiceProvider::class,
];
