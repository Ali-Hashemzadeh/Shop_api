<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Services;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;

/**
 * Unicode-aware slugs. Str::slug() transliterates to ASCII and turns a Persian
 * title into an empty string, so Blog keeps letters and digits from every
 * script (`راهنمای-خرید-گوشی`) and only collapses separators.
 */
final class BlogSlugger
{
    /** A slug: letters/digits from any script, single hyphens between words. */
    public const PATTERN = '/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u';

    public static function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace("\u{200C}", '-', $value);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $value);
        $value = trim((string) preg_replace('/-+/', '-', $value), '-');

        return mb_substr($value, 0, 180, 'UTF-8');
    }

    /**
     * Whether a slug would be mistaken for a post public code (`bdb-XXXXXX`).
     * Public reads route a code-shaped value to the exact code lookup, so such a
     * slug would be unreachable; validation refuses it.
     */
    public static function collidesWithPublicCode(string $slug): bool
    {
        return PublicCodeGenerator::matches($slug, PublicCodeEntity::BlogPost);
    }

    /**
     * The first free variant of `$base` (`base`, `base-2`, `base-3`, …) according
     * to the caller-supplied existence check.
     *
     * @param  callable(string): bool  $taken
     */
    public static function unique(string $base, callable $taken): string
    {
        $base = $base === '' ? 'post' : $base;
        $candidate = $base;

        for ($i = 2; $taken($candidate) || self::collidesWithPublicCode($candidate); $i++) {
            $candidate = $base.'-'.$i;
        }

        return $candidate;
    }
}
