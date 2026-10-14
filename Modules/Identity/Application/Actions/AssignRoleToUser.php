<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;

/**
 * Assign an existing RBAC role to an account.
 *
 * Additive like every role change in this module — never syncRoles, so the
 * account keeps every role it already holds. Idempotent: a role the user
 * already has is left untouched. The `admin` role never reaches this action;
 * `UserRoleRequest` refuses it before validation.
 */
class AssignRoleToUser
{
    public function handle(User $user, string $role): User
    {
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }
}
