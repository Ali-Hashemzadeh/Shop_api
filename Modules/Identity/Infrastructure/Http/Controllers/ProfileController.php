<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\ShowMyAddresses;
use Modules\Identity\Application\Actions\ShowProfile;
use Modules\Identity\Application\Actions\UpdateProfile;
use Modules\Identity\Infrastructure\Http\Requests\ShowProfileRequest;
use Modules\Identity\Infrastructure\Http\Requests\UpdateProfileRequest;
use Modules\Identity\Infrastructure\Http\Resources\AddressResource;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;

class ProfileController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function showMe(ShowProfileRequest $request, ShowProfile $action): JsonResponse
    {
        $this->authorize('view', $request->user());

        $user = $action->handle($request->user());

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function updateMe(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $this->authorize('update', $request->user());

        $user = $action->handle($request->user(), $request->validated());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => new UserResource($user),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function myAddresses(ShowProfileRequest $request, ShowMyAddresses $action): JsonResponse
    {
        $this->authorize('viewAddresses', $request->user());

        return response()->json([
            'data' => AddressResource::collection($action->handle($request->user())),
        ]);
    }
}
