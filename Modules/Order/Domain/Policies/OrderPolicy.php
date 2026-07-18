<?php

declare(strict_types=1);

namespace Modules\Order\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Admin/operator authorization for reading and cancelling orders. Permission-based
 * (any user granted the permission may act) and typehinted against the framework
 * Authorizable contract — never against another module's User model.
 */
class OrderPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('order.view-admin');
    }

    public function view(Authorizable $user): bool
    {
        return $user->can('order.view-admin');
    }

    public function cancel(Authorizable $user): bool
    {
        return $user->can('order.cancel-admin');
    }
}
