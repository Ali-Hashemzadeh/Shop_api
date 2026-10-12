<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogPlacement;

/** Remove one article from a keyed editorial slot. */
final class DeleteBlogPlacementAction
{
    public function handle(BlogPlacement $placement): void
    {
        $placement->delete();
    }
}
