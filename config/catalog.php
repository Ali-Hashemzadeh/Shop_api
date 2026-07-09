<?php

return [
    'cache' => [
        'enabled' => env('CATALOG_CACHE_ENABLED', true),
        'ttl' => env('CATALOG_CACHE_TTL', 3600),
    ],
];
