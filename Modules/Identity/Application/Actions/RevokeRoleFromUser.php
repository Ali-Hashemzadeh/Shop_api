<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;

/**
 * Remove one RBAC role from an account, leaving its other roles intact.
 * Idempotent: removing a role the user does not hold is a no-op. The `admin`
 * role is refused at the HTTP layer (`UserRoleRequest`).
 */
class RevokeRoleFromUser
{
    public function handle(User $user, string $role): User
    {
        if ($user->hasRole($role)) {
            $user->removeRole($role);
        }

        return $user->fresh();
    }
}
