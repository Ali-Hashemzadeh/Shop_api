<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\UpdateProfile;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Http\Requests\UpdateProfileRequest;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;

class AdminUserController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateProfileRequest $request, User $user, UpdateProfile $action): JsonResponse
    {
        $this->authorize('update', $user);

        $updatedUser = $action->handle($user, $request->validated());

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => new UserResource($updatedUser),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
