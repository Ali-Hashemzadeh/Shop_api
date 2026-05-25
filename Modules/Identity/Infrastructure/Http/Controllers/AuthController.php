<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Actions\LoginUserWithPassword;
use Modules\Identity\Application\Actions\LogoutCurrentToken;
use Modules\Identity\Application\Actions\RegisterUser;
use Modules\Identity\Infrastructure\Http\Requests\LoginRequest;
use Modules\Identity\Infrastructure\Http\Requests\RegisterRequest;
use Modules\Identity\Infrastructure\Http\Resources\AuthUserResource;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'message' => $result['message'],
            'user' => new AuthUserResource($result['user']),
            'token' => $result['token'],
        ], 201);
    }

    public function login(LoginRequest $request, LoginUserWithPassword $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'message' => $result['message'],
            'user' => new AuthUserResource($result['user']),
            'token' => $result['token'],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new AuthUserResource($request->user()),
        ]);
    }

    public function logout(Request $request, LogoutCurrentToken $action): JsonResponse
    {
        $action->handle($request->user());

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
