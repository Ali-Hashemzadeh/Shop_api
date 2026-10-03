<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    | The module never names a provider in business logic — it talks to
    | AIProviderInterface, resolved by AIProviderFactory from the value below.
    | There is intentionally NO fake/log fallback: when the active provider is
    | missing its required configuration (e.g. AVALAI_API_KEY), generation fails
    | loudly and the failure is written to the generation log system. It never
    | fabricates data or silently degrades.
    */
    'ai' => [
        'provider' => env('PRODUCT_REVIEW_AI_PROVIDER', 'avalai'),

        'providers' => [
            'avalai' => [
                'api_key' => env('AVALAI_API_KEY', ''),
                // AvalAI exposes an OpenAI-compatible surface.
                'base_url' => env('AVALAI_BASE_URL', 'https://api.avalai.ir/v1'),
                'model' => env('AVALAI_MODEL', 'deepseek-v4.1-flash'),
                'timeout' => (int) env('AVALAI_TIMEOUT', 120),
                'temperature' => (float) env('AVALAI_TEMPERATURE', 0.85),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External review sources
    |--------------------------------------------------------------------------
    | Sources are addressed by stable `code` (persisted on review_sources.driver);
    | ReviewSourceFactory maps a driver code to a concrete adapter. Adding a new
    | marketplace is a new adapter + a config block, never an `if ($code === ...)`.
    */
    'sources' => [
        'digikala' => [
            'base_url' => env('DIGIKALA_BASE_URL', 'https://api.digikala.com'),
            // Web product URL pattern — the marketplace product URL carries dkp-{id}.
            'web_base_url' => env('DIGIKALA_WEB_BASE_URL', 'https://www.digikala.com'),
            'search_path' => env('DIGIKALA_SEARCH_PATH', '/discovery/api/v2/search'),
            'reviews_path' => env('DIGIKALA_REVIEWS_PATH', '/v1/rate-review/products/{product_id}/'),
            'timeout' => (int) env('DIGIKALA_TIMEOUT', 30),
            // Marketplace review pages are ~10 items; cap the crawl so a broken
            // paginator can never loop forever.
            'max_pages' => (int) env('DIGIKALA_MAX_PAGES', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Review collection
    |--------------------------------------------------------------------------
    */
    'collection' => [
        // Approximately how many external reviews to gather before analysis.
        'review_limit' => (int) env('PRODUCT_REVIEW_AI_COLLECT_LIMIT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Draft generation
    |--------------------------------------------------------------------------
    */
    'generation' => [
        'default_count' => (int) env('PRODUCT_REVIEW_AI_DEFAULT_COUNT', 3),
        'max_count' => (int) env('PRODUCT_REVIEW_AI_MAX_COUNT', 20),
    ],
];
