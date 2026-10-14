<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Spatie\Permission\Models\Role;

/**
 * Add permissions to an existing role's bundle.
 *
 * Additive by construction — never syncPermissions — so grants made by module
 * seeders survive. Idempotent: names the role already holds are skipped by the
 * package. Spatie flushes the permission cache on the write, so the new
 * capability is effective from the very next request.
 */
class GrantPermissionsToRole
{
    /**
     * @param  list<string>  $permissions
     */
    public function handle(Role $role, array $permissions): Role
    {
        $role->givePermissionTo($permissions);

        return $role->fresh();
    }
}
