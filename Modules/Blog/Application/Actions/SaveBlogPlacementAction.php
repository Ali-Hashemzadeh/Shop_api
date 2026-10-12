<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogPlacement;

/**
 * Create or update one keyed editorial placement. Any post may be *placed*
 * (editors schedule slots ahead of publication); public placement reads only
 * ever render posts that are publicly visible at read time.
 */
final class SaveBlogPlacementAction
{
    public function handle(?BlogPlacement $placement, array $data): BlogPlacement
    {
        $placement ??= new BlogPlacement;

        foreach (['placement_key', 'blog_post_id', 'starts_at', 'ends_at', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $placement->setAttribute($field, $data[$field]);
            }
        }

        $placement->sort_order ??= 0;
        $placement->save();

        return $placement->fresh();
    }
}
