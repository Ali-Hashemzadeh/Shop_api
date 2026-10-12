<?php

/*
|--------------------------------------------------------------------------
| Blog / CMS module tunables
|--------------------------------------------------------------------------
|
| Every value here is a tunable, never a business rule. Frontend URLs are
| composed from config('frontend.url') so canonical/feed/sitemap links point
| at the storefront, not at this API.
|
*/

return [

    // Storefront path segments: {FRONTEND_URL}/{post_path}/{slug} etc.
    'frontend' => [
        'post_path' => trim((string) env('BLOG_FRONTEND_POST_PATH', 'blog'), '/'),
        'category_path' => trim((string) env('BLOG_FRONTEND_CATEGORY_PATH', 'blog/category'), '/'),
        'tag_path' => trim((string) env('BLOG_FRONTEND_TAG_PATH', 'blog/tag'), '/'),
        'author_path' => trim((string) env('BLOG_FRONTEND_AUTHOR_PATH', 'blog/author'), '/'),
    ],

    // Rich-text sanitization (symfony/html-sanitizer behind BlogHtmlSanitizerInterface).
    'sanitizer' => [
        // Hard ceiling on input bytes. The vendor default (20 KB) would silently
        // truncate a long article, so it is raised explicitly; requests are also
        // capped by validation (`content` max) well before this.
        'max_input_length' => (int) env('BLOG_SANITIZER_MAX_INPUT_LENGTH', 2_000_000),
        // Optional allow-list of image hosts (e.g. the storage domain). Null = any
        // http(s) host. Relative URLs (same-origin Media URLs) are always allowed.
        'allowed_media_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BLOG_SANITIZER_MEDIA_HOSTS', ''))
        ))) ?: null,
    ],

    'content' => [
        // Validation cap on stored article HTML, in characters.
        'max_length' => (int) env('BLOG_CONTENT_MAX_LENGTH', 1_000_000),
    ],

    'comments' => [
        // Depth of a top-level comment is 1; a reply to a depth-3 comment is refused.
        'max_depth' => (int) env('BLOG_COMMENT_MAX_DEPTH', 3),
        'max_length' => (int) env('BLOG_COMMENT_MAX_LENGTH', 5000),
        // Per-user creates (comments, replies, reports) per minute.
        'rate_limit_per_minute' => (int) env('BLOG_COMMENT_RATE_LIMIT', 10),
    ],

    // Signed, time-limited preview URLs for unpublished posts.
    'preview' => [
        'ttl_minutes' => (int) env('BLOG_PREVIEW_TTL_MINUTES', 60),
    ],

    // View / product-click deduplication (Blog's own engagement counters).
    'engagement' => [
        'view_dedup_minutes' => (int) env('BLOG_VIEW_DEDUP_MINUTES', 1440),
        'click_dedup_minutes' => (int) env('BLOG_CLICK_DEDUP_MINUTES', 60),
        // User agents matching this case-insensitive pattern are never counted.
        'bot_user_agent_pattern' => (string) env(
            'BLOG_BOT_UA_PATTERN',
            'bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|curl|wget|python-requests'
        ),
    ],

    // Weights of the `popular` sort: an integer score over Blog's own counters.
    'popularity_weights' => [
        'views' => 1,
        'likes' => 3,
        'bookmarks' => 4,
        'comments' => 5,
    ],

    'related' => [
        'default_limit' => 4,
        'max_limit' => 20,
    ],

    'feed' => [
        'limit' => (int) env('BLOG_FEED_LIMIT', 20),
        'title' => (string) (env('BLOG_FEED_TITLE') ?: env('APP_NAME', 'Blog')),
        'description' => (string) env('BLOG_FEED_DESCRIPTION', ''),
        'language' => (string) env('BLOG_FEED_LANGUAGE', 'fa-IR'),
    ],

    'sitemap' => [
        // Tag archive pages are thin content on many sites; opt in explicitly.
        'include_tags' => (bool) env('BLOG_SITEMAP_INCLUDE_TAGS', false),
        'include_authors' => (bool) env('BLOG_SITEMAP_INCLUDE_AUTHORS', true),
    ],

    'home' => [
        'featured_limit' => 6,
        'latest_limit' => 10,
        // Placement keys rendered by GET /api/v1/blog. Placements are data, not
        // schema: a new homepage slot is a new key here + rows in blog_placements.
        'placement_keys' => ['blog.home.hero', 'blog.home.editor_picks', 'blog.home.secondary'],
    ],
];
