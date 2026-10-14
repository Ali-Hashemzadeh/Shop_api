<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Spatie\Permission\Models\Role;

/**
 * Remove permissions from an existing role's bundle, leaving everything else
 * intact. Idempotent: names the role does not hold are ignored. Spatie flushes
 * the permission cache on the write.
 */
class RevokePermissionsFromRole
{
    /**
     * @param  list<string>  $permissions
     */
    public function handle(Role $role, array $permissions): Role
    {
        $role->revokePermissionTo($permissions);

        return $role->fresh();
    }
}
