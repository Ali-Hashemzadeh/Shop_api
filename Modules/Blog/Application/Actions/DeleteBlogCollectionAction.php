<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogCollection;

/** Delete a collection. Its curated list (pivot) cascades; posts are untouched. */
final class DeleteBlogCollectionAction
{
    public function handle(BlogCollection $collection): void
    {
        $collection->delete();
    }
}
