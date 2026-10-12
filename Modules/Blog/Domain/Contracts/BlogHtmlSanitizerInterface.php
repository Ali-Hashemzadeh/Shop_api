<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Contracts;

/**
 * Turns untrusted rich-text editor HTML into safe article HTML.
 *
 * The rest of the Blog module depends on this abstraction only, never on a
 * vendor sanitizer, so the implementation can be swapped without touching any
 * Action. Implementations must use an allow-list (never a deny-list or regex):
 * anything not explicitly allowed — scripts, event-handler attributes, inline
 * styles, iframes, `javascript:`/`data:` URLs — must not survive.
 */
interface BlogHtmlSanitizerInterface
{
    public function sanitize(string $html): string;
}
