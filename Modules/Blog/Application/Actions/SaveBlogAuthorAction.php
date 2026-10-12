<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Services\BlogSlugger;

/**
 * Create or update a Blog author profile. The optional `user_id` link was
 * validated against Identity (IdentityManagerInterface) by the request; Blog
 * stores it as a loose reference.
 */
final class SaveBlogAuthorAction
{
    private const FIELDS = ['user_id', 'display_name', 'slug', 'bio', 'media_id', 'job_title', 'is_active', 'seo_title', 'seo_description'];

    public function handle(?BlogAuthor $author, array $data): BlogAuthor
    {
        $author ??= new BlogAuthor;

        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $author->setAttribute($field, $data[$field]);
            }
        }

        if (blank($author->slug)) {
            $author->slug = BlogSlugger::unique(
                BlogSlugger::slugify((string) $author->display_name),
                fn (string $candidate) => BlogAuthor::query()->where('slug', $candidate)->exists(),
            );
        }

        $author->save();

        return $author->fresh();
    }
}
