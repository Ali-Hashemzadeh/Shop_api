<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Support;

/**
 * Absolute storefront URLs for Blog pages, composed from `frontend.url` and the
 * `blog.frontend.*` path segments — canonical links, feeds, and sitemaps always
 * point at the storefront, never at this API. Slugs may be Persian, so path
 * segments are rawurlencoded.
 */
final class BlogUrls
{
    public static function post(string $slug): string
    {
        return self::build((string) config('blog.frontend.post_path', 'blog'), $slug);
    }

    public static function category(string $slug): string
    {
        return self::build((string) config('blog.frontend.category_path', 'blog/category'), $slug);
    }

    public static function tag(string $slug): string
    {
        return self::build((string) config('blog.frontend.tag_path', 'blog/tag'), $slug);
    }

    public static function author(string $slug): string
    {
        return self::build((string) config('blog.frontend.author_path', 'blog/author'), $slug);
    }

    public static function blogHome(): string
    {
        return rtrim((string) config('frontend.url'), '/').'/'.trim((string) config('blog.frontend.post_path', 'blog'), '/');
    }

    private static function build(string $path, string $slug): string
    {
        return rtrim((string) config('frontend.url'), '/').'/'.trim($path, '/').'/'.rawurlencode($slug);
    }
}
