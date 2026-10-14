<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Infrastructure\Http\Requests\ListPermissionsRequest;
use Modules\Identity\Infrastructure\Http\Resources\PermissionResource;
use Spatie\Permission\Models\Permission;

/**
 * Read-only catalogue of every seeded permission (`rbac.view`) — the source for
 * the admin UI's "grant to role" picker. Permissions are module-seeded rows;
 * nothing here creates them at runtime.
 */
class AdminPermissionController extends Controller
{
    public function index(ListPermissionsRequest $request): JsonResponse
    {
        $perPage = (int) ($request->validated('per_page', 15));
        $search = $request->validated('search');
        $group = $request->validated('group');

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->when(
                $search !== null,
                fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_').'%')
            )
            ->when(
                $group !== null,
                fn ($query) => $query->where('name', 'like', addcslashes($group, '%_').'.%')
            )
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => PermissionResource::collection($permissions->items()),
            'meta' => [
                'current_page' => $permissions->currentPage(),
                'last_page' => $permissions->lastPage(),
                'per_page' => $permissions->perPage(),
                'total' => $permissions->total(),
                'from' => $permissions->firstItem(),
                'to' => $permissions->lastItem(),
            ],
            'links' => [
                'first' => $permissions->url(1),
                'last' => $permissions->url($permissions->lastPage()),
                'prev' => $permissions->previousPageUrl(),
                'next' => $permissions->nextPageUrl(),
            ],
        ]);
    }
}
