<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests\Concerns;

use Modules\Blog\Domain\Models\BlogPost;

/**
 * Resolve the `{post}` route id inside authorize(), so an unknown id is a 404
 * before any 403/422 (the Review precedent). Soft-deleted posts are not
 * editable and resolve as missing.
 */
trait ResolvesAdminBlogPost
{
    private ?BlogPost $resolvedPost = null;

    public function blogPost(): BlogPost
    {
        return $this->resolvedPost ??= BlogPost::query()->findOrFail((int) $this->route('post'));
    }
}
