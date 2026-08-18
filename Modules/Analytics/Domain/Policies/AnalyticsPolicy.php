<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Admin authorization for viewing analytics reports and dashboards.
 *
 * Permission-based (`analytics.view`) and typehinted against the framework
 * Authorizable contract — never against another module's User model.
 */
class AnalyticsPolicy
{
    public function view(Authorizable $user): bool
    {
        return (bool) $user->can('analytics.view');
    }
}
