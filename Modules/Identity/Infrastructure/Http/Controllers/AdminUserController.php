<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\CreateUserByAdmin;
use Modules\Identity\Application\Actions\GrantDeliveryRole;
use Modules\Identity\Application\Actions\UpdateProfile;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Http\Requests\GrantDeliveryRoleRequest;
use Modules\Identity\Infrastructure\Http\Requests\ListProfileRequest;
use Modules\Identity\Infrastructure\Http\Requests\StoreUserRequest;
use Modules\Identity\Infrastructure\Http\Requests\UpdateProfileRequest;
use Modules\Identity\Infrastructure\Http\Resources\AddressResource;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

class AdminUserController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(ListProfileRequest $request, UserRepositoryInterface $users): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $perPage = (int) ($request->validated('per_page', 15));

        $paginatedUsers = $users->paginate($perPage, $request->validated('role'));

        return response()->json([
            'data' => UserResource::collection($paginatedUsers->items()),
            'meta' => [
                'current_page' => $paginatedUsers->currentPage(),
                'last_page' => $paginatedUsers->lastPage(),
                'per_page' => $paginatedUsers->perPage(),
                'total' => $paginatedUsers->total(),
                'from' => $paginatedUsers->firstItem(),
                'to' => $paginatedUsers->lastItem(),
            ],
            'links' => [
                'first' => $paginatedUsers->url(1),
                'last' => $paginatedUsers->url($paginatedUsers->lastPage()),
                'prev' => $paginatedUsers->previousPageUrl(),
                'next' => $paginatedUsers->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Create a shopper or a delivery worker. Authorization (including the extra
     * delivery grant) lives in StoreUserRequest, so an unauthorized caller is
     * rejected with 403 before validation runs.
     */
    public function store(StoreUserRequest $request, CreateUserByAdmin $action): JsonResponse
    {
        $user = $action->handle($request->validated());

        return response()->json([
            'message' => 'User created successfully.',
            'data' => new UserResource($user),
        ], 201);
    }

    /**
     * Grant delivery responsibility to an existing account, keeping every role it
     * already holds. Idempotent — a second call returns the same 200.
     */
    public function grantDeliveryRole(GrantDeliveryRoleRequest $request, User $user, GrantDeliveryRole $action): JsonResponse
    {
        return response()->json([
            'message' => 'Delivery role granted successfully.',
            'data' => new UserResource($action->handle($user)),
        ]);
    }

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
    public function addresses(User $user, AddressRepositoryInterface $addresses): JsonResponse
    {
        $this->authorize('view', $user);

        return response()->json([
            'data' => AddressResource::collection($addresses->listForUser($user)),
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
