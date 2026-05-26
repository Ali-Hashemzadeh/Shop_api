<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Actions\ShowProfile;
use Modules\Identity\Application\Actions\UpdateProfile;
use Modules\Identity\Infrastructure\Http\Requests\UpdateProfileRequest;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;

class ProfileController extends Controller
{
    public function show(Request $request, ShowProfile $action): JsonResponse
    {
        $user = $action->handle($request->user());

        return response()->json([
            'data' => new UserResource($user),
        ]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $action): JsonResponse
    {
        $user = $action->handle($request->user(), $request->validated());

        return response()->json([
            'message' => 'Profile updated successfully.',
            'data' => new UserResource($user),
        ]);
    }
}
