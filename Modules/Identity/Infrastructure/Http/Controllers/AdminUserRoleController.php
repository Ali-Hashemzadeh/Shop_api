<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\AssignRoleToUser;
use Modules\Identity\Application\Actions\RevokeRoleFromUser;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Http\Requests\UserRoleRequest;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;

/**
 * Assign/revoke an RBAC role on an account (`rbac.manage`).
 *
 * The role name arrives in the body, and `UserRoleRequest` refuses the `admin`
 * role outright — this surface hands out capability bundles, it never mints or
 * demotes administrators. Mutations are additive and idempotent, like every
 * other role change in the module.
 */
class AdminUserRoleController extends Controller
{
    public function assign(UserRoleRequest $request, User $user, AssignRoleToUser $action): JsonResponse
    {
        return response()->json([
            'message' => 'Role assigned successfully.',
            'data' => new UserResource($action->handle($user, $request->validated('role'))),
        ]);
    }

    public function revoke(UserRoleRequest $request, User $user, RevokeRoleFromUser $action): JsonResponse
    {
        return response()->json([
            'message' => 'Role revoked successfully.',
            'data' => new UserResource($action->handle($user, $request->validated('role'))),
        ]);
    }
}
