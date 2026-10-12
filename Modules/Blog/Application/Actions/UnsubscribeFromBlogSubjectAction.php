<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Models\BlogSubscription;

/** Stop following. The controller resolves the row scoped to the caller. */
final class UnsubscribeFromBlogSubjectAction
{
    public function handle(BlogSubscription $subscription): void
    {
        $subscription->delete();
    }
}
