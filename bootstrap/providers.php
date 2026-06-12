<?php

return [
    App\Providers\AppServiceProvider::class,
    \Modules\Identity\Infrastructure\Providers\IdentityServiceProvider::class,
    Modules\Catalog\Infrastructure\Providers\CatalogServiceProvider::class,
    Modules\Media\Infrastructure\Providers\MediaServiceProvider::class,
];
