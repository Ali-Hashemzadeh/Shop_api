<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\Models\BlogCollection;
use Modules\Blog\Domain\Services\BlogSlugger;

/**
 * Create or update an editorial collection and (when `post_ids` is sent)
 * replace its curated, ordered post list — atomically.
 */
final class SaveBlogCollectionAction
{
    public function handle(?BlogCollection $collection, array $data): BlogCollection
    {
        return DB::transaction(function () use ($collection, $data): BlogCollection {
            $collection ??= new BlogCollection;

            foreach (['name', 'slug', 'description', 'is_active', 'starts_at', 'ends_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $collection->setAttribute($field, $data[$field]);
                }
            }

            if (blank($collection->slug)) {
                $collection->slug = BlogSlugger::unique(
                    BlogSlugger::slugify((string) $collection->name),
                    fn (string $candidate) => BlogCollection::query()->where('slug', $candidate)->exists(),
                );
            }

            $collection->save();

            if (array_key_exists('post_ids', $data)) {
                $sync = [];
                foreach (array_values(array_unique(array_map('intval', (array) $data['post_ids']))) as $index => $postId) {
                    $sync[$postId] = ['sort_order' => $index];
                }
                $collection->posts()->sync($sync);
            }

            return $collection->fresh();
        });
    }
}
