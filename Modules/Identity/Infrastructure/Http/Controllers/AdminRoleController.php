<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Identity\Application\Actions\GrantPermissionsToRole;
use Modules\Identity\Application\Actions\RevokePermissionsFromRole;
use Modules\Identity\Infrastructure\Http\Requests\ListRolesRequest;
use Modules\Identity\Infrastructure\Http\Requests\RolePermissionsRequest;
use Modules\Identity\Infrastructure\Http\Requests\ShowRoleRequest;
use Modules\Identity\Infrastructure\Http\Resources\RoleResource;
use Spatie\Permission\Models\Role;

/**
 * Admin management of RBAC roles and their permission sets (`rbac.view` to read,
 * `rbac.manage` to change). Roles are addressed by *name* on the `web` guard;
 * unknown names are 404.
 *
 * The `admin` role is deliberately read-only here: its bundle is maintained by
 * the module seeders ("admin receives everything") and editing it through the
 * API could lock the whole back office out.
 */
class AdminRoleController extends Controller
{
    public function index(ListRolesRequest $request): JsonResponse
    {
        $perPage = (int) ($request->validated('per_page', 15));

        $roles = $this->roleQuery()
            ->withCount('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => RoleResource::collection($roles->items()),
            'meta' => [
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'per_page' => $roles->perPage(),
                'total' => $roles->total(),
                'from' => $roles->firstItem(),
                'to' => $roles->lastItem(),
            ],
            'links' => [
                'first' => $roles->url(1),
                'last' => $roles->url($roles->lastPage()),
                'prev' => $roles->previousPageUrl(),
                'next' => $roles->nextPageUrl(),
            ],
        ]);
    }

    public function show(ShowRoleRequest $request, string $role): JsonResponse
    {
        return response()->json([
            'data' => new RoleResource($this->detailed($this->resolveRole($role))),
        ]);
    }

    public function grantPermissions(RolePermissionsRequest $request, string $role, GrantPermissionsToRole $action): JsonResponse
    {
        $roleModel = $this->resolveRole($role);
        $this->assertEditable($roleModel);

        $action->handle($roleModel, $request->validated('permissions'));

        return response()->json([
            'message' => 'Permissions granted successfully.',
            'data' => new RoleResource($this->detailed($roleModel)),
        ]);
    }

    public function revokePermissions(RolePermissionsRequest $request, string $role, RevokePermissionsFromRole $action): JsonResponse
    {
        $roleModel = $this->resolveRole($role);
        $this->assertEditable($roleModel);

        $action->handle($roleModel, $request->validated('permissions'));

        return response()->json([
            'message' => 'Permissions revoked successfully.',
            'data' => new RoleResource($this->detailed($roleModel)),
        ]);
    }

    /** Roles are addressed by name on the `web` guard; unknown names are 404. */
    private function resolveRole(string $name): Role
    {
        return $this->roleQuery()
            ->where('name', $name)
            ->firstOrFail();
    }

    /**
     * An RBAC query anchored to the `web` guard.
     *
     * Spatie's Role constructor stamps `guard_name` from the app's *current*
     * default guard, and `auth:sanctum` switches that default to `sanctum`
     * (whose provider is null) for the duration of an authenticated request. A
     * plain `Role::query()` would therefore build its `users` relation against a
     * model that cannot resolve a user class — so the base instance is created
     * with the only guard RBAC uses here.
     */
    private function roleQuery(): Builder
    {
        return (new Role(['guard_name' => 'web']))
            ->newQuery()
            ->where('guard_name', 'web');
    }

    /**
     * `admin` is protected: writes against its permission set are refused with a
     * 422 on the `role` field, while reads stay open so it still renders in the
     * admin UI.
     */
    private function assertEditable(Role $role): void
    {
        if ($role->name === 'admin') {
            throw ValidationException::withMessages([
                'role' => ['The admin role is protected and its permissions cannot be changed.'],
            ]);
        }
    }

    /** The full detail shape every write response returns. */
    private function detailed(Role $role): Role
    {
        return $role->fresh()
            ->loadCount('permissions')
            ->loadCount('users')
            ->load('permissions');
    }
}
