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

    /*
    |--------------------------------------------------------------------------
    | Queue job
    |--------------------------------------------------------------------------
    | `job_timeout` is GenerateProductAIReviewsJob::$timeout (seconds). The
    | default (1200 s) covers the worst case of the current HTTP limits:
    | collection ≤ DIGIKALA_MAX_PAGES × DIGIKALA_TIMEOUT (30 × 30 s = 900 s) plus
    | two AI calls ≤ 2 × AVALAI_TIMEOUT (240 s).
    |
    | The queue connection's `retry_after` (DB_QUEUE_RETRY_AFTER for the database
    | driver, default 90) MUST be larger than this value, otherwise a second
    | worker may receive the same job while the first is still running. The
    | atomic claim still prevents a second pipeline, but the redelivery is
    | wasted. Laravel only enforces $timeout where pcntl is available (Linux).
    |
    | The job never retries automatically ($tries = 1): an automatic re-run would
    | repeat paid AI calls. Recovery is the admin retry endpoint.
    */
    'queue' => [
        'job_timeout' => (int) env('PRODUCT_REVIEW_AI_JOB_TIMEOUT', 1200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & stuck-generation detection
    |--------------------------------------------------------------------------
    | Used by both the monitoring API (`is_suspected_stuck`, stats) and the
    | `ai-reviews:detect-stuck-generations` command — one rule set, no drift.
    |
    | pending_threshold_seconds     a `pending` run older than this is SUSPECTED
    |                               (no worker picked it up). Never auto-failed:
    |                               a backlog or stopped worker is not proof the
    |                               job is lost.
    | inactivity_threshold_seconds  a `processing` run whose last heartbeat is
    |                               older than this is SUSPECTED. The effective
    |                               value is never below the longest single
    |                               blocking stage derived from the HTTP timeouts
    |                               (heartbeats are written at stage boundaries,
    |                               not during a long HTTP call).
    | processing_timeout_seconds    a `processing` run started longer ago than this
    |                               AND inactive is treated as ABANDONED and may be
    |                               failed (PROCESSING_TIMEOUT) — only when this is
    |                               larger than queue.job_timeout, so no live
    |                               worker can still legitimately own it.
    | auto_fail_abandoned           false = only report, never change state.
    */
    'monitoring' => [
        'pending_threshold_seconds' => (int) env('PRODUCT_REVIEW_AI_PENDING_STUCK_AFTER', 900),
        'inactivity_threshold_seconds' => (int) env('PRODUCT_REVIEW_AI_INACTIVITY_STUCK_AFTER', 1200),
        'processing_timeout_seconds' => (int) env('PRODUCT_REVIEW_AI_PROCESSING_TIMEOUT', 1800),
        'auto_fail_abandoned' => (bool) env('PRODUCT_REVIEW_AI_AUTO_FAIL_ABANDONED', true),
    ],
];
