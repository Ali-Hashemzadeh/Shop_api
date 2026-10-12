<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Services;

/**
 * Search-only folding for Persian/Arabic text.
 *
 * Applied symmetrically to the derived `search_text` column and to the search
 * query, so `كتاب` finds `کتاب` and `۱۴۰۳` finds `1403`. It is NEVER applied to
 * stored article HTML or titles — display content is kept exactly as written.
 */
final class PersianTextNormalizer
{
    private const MAP = [
        // Arabic letter variants → Persian.
        'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی',
        'ك' => 'ک',
        'ة' => 'ه', 'ۀ' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'ٱ' => 'ا', 'آ' => 'ا',
        'ؤ' => 'و',
        // Persian + Arabic-Indic digits → ASCII.
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // Zero-width non-joiner / joiner and other invisible joiners → space.
        "\u{200C}" => ' ', "\u{200D}" => '', "\u{200F}" => '', "\u{200E}" => '', "\u{FEFF}" => '',
        // Tatweel (kashida) carries no meaning for search.
        'ـ' => '',
    ];

    public static function normalize(string $text): string
    {
        $text = strtr($text, self::MAP);

        // Strip Arabic diacritics (harakat, tanwin, shadda, sukun, superscript alef).
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);

        $text = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Plain-text projection of (already sanitized) HTML for the search column.
     * strip_tags here is a text extractor for indexing, not a security control —
     * the HTML was sanitized before it reached this point.
     */
    public static function searchableTextFromHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Keep word boundaries between block elements before dropping tags.
        $spaced = (string) preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/td|\/th|\/blockquote|\/figcaption)[^>]*>/i', ' $0', $html);

        return self::normalize(html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
