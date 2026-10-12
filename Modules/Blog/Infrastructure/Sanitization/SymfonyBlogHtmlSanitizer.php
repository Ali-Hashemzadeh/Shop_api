<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Sanitization;

use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * symfony/html-sanitizer behind the Blog sanitizer contract.
 *
 * The configuration is an explicit allow-list sized for a normal article
 * editor. The library parses the HTML into a DOM (masterminds/html5) and
 * rebuilds it from allowed nodes only, so this is a parser-based sanitizer —
 * never regex or strip_tags.
 *
 * What survives:
 *  - the editor's structural/inline elements (headings, lists, tables,
 *    quotes, code, figures …) with a minimal attribute set;
 *  - `dir`/`lang` on text blocks, so Persian/RTL paragraphs keep direction;
 *  - links with http/https/mailto/tel or relative URLs only; every link is
 *    forced to `rel="noopener noreferrer nofollow"`, so `target="_blank"` can
 *    never open a tab-nabbing window;
 *  - images with http/https or relative sources only (optionally limited to
 *    configured media hosts — normally the Media storage domain).
 *
 * What never survives: <script>, <style>, <iframe>, <object>, <form>,
 * event-handler attributes, `style`, `javascript:`/`vbscript:`/`data:` URLs.
 */
final class SymfonyBlogHtmlSanitizer implements BlogHtmlSanitizerInterface
{
    private const TEXT_BLOCK_ATTRIBUTES = ['dir', 'lang'];

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return trim($this->sanitizer()->sanitize($html));
    }

    private function sanitizer(): HtmlSanitizer
    {
        return $this->sanitizer ??= new HtmlSanitizer($this->config());
    }

    private function config(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(max(1, (int) config('blog.sanitizer.max_input_length', 2_000_000)))
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias();

        $mediaHosts = config('blog.sanitizer.allowed_media_hosts');
        if (is_array($mediaHosts) && $mediaHosts !== []) {
            $config = $config->allowMediaHosts($mediaHosts);
        }

        foreach (['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'li', 'ul', 'ol', 'figcaption'] as $element) {
            $config = $config->allowElement($element, self::TEXT_BLOCK_ATTRIBUTES);
        }

        foreach (['br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'thead', 'tbody', 'tr', 'figure'] as $element) {
            $config = $config->allowElement($element);
        }

        return $config
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->allowElement('table', ['dir'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan'])
            // `class` only on code/pre so editors can mark a language for syntax
            // highlighting (e.g. `language-php`); classes cannot execute.
            ->allowElement('pre', ['class', 'dir'])
            ->allowElement('code', ['class'])
            // Dangerous containers are dropped together with their content.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->dropElement('template');
    }
}
