<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Domain\Services\BlogSlugger;

/** Create or update a flat Blog tag. The slug defaults to a unique slug of the name. */
final class SaveBlogTagAction
{
    public function handle(?BlogTag $tag, array $data): BlogTag
    {
        $tag ??= new BlogTag;

        foreach (['name', 'slug', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $tag->setAttribute($field, $data[$field]);
            }
        }

        if (blank($tag->slug)) {
            $tag->slug = BlogSlugger::unique(
                BlogSlugger::slugify((string) $tag->name),
                fn (string $candidate) => BlogTag::query()->where('slug', $candidate)->exists(),
            );
        }

        $tag->save();

        return $tag->fresh();
    }
}
