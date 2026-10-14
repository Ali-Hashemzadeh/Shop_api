<?php

namespace Modules\Identity\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * One RBAC role. The `roles` and `permissions` tables are Identity's domain
 * (seeded by RolesAndPermissionsSeeder and the module seeders), so this resource
 * reads the configured Spatie Role row directly.
 *
 * `permissions` is emitted only when the relation is loaded: the paginated list
 * pays for counts only, while the detail view and every write response carry the
 * full sorted set.
 *
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'guard_name' => $this->guard_name,
            'permissions_count' => (int) ($this->permissions_count ?? 0),
            'users_count' => (int) ($this->users_count ?? 0),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn (): array => $this->permissions->pluck('name')->sort()->values()->all()
            ),
        ];
    }
}
