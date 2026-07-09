<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Actions\CheckUserStatus;
use Modules\Identity\Application\Actions\LoginWithPassword;
use Modules\Identity\Application\Actions\LogoutCurrentToken;
use Modules\Identity\Application\Actions\RequestOtp;
use Modules\Identity\Application\Actions\SetPassword;
use Modules\Identity\Application\Actions\VerifyOtp;
use Modules\Identity\Infrastructure\Http\Requests\CheckUserRequest;
use Modules\Identity\Infrastructure\Http\Requests\LoginPasswordRequest;
use Modules\Identity\Infrastructure\Http\Requests\RequestOtpRequest;
use Modules\Identity\Infrastructure\Http\Requests\SetPasswordRequest;
use Modules\Identity\Infrastructure\Http\Requests\VerifyOtpRequest;
use Modules\Identity\Infrastructure\Http\Resources\AuthUserResource;

class AuthController extends Controller
{
    public function checkUser(CheckUserRequest $request, CheckUserStatus $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'is_new_user' => $result['is_new_user'],
            'allowed_methods' => $result['allowed_methods'],
        ]);
    }

    public function loginPassword(LoginPasswordRequest $request, LoginWithPassword $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'message' => $result['message'],
            'user' => new AuthUserResource($result['user']),
            'token' => $result['token'],
        ]);
    }

    public function requestOtp(RequestOtpRequest $request, RequestOtp $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'message' => $result['message'],
            'expires_in' => $result['expires_in'],
            'is_new_user' => $result['is_new_user'],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request, VerifyOtp $action): JsonResponse
    {
        $result = $action->handle($request->validated());

        return response()->json([
            'message' => $result['message'],
            'user' => new AuthUserResource($result['user']),
            'token' => $result['token'],
            'has_password' => $result['has_password'],
        ]);
    }

    public function setPassword(SetPasswordRequest $request, SetPassword $action): JsonResponse
    {
        $user = $action->handle($request->user(), $request->validated('password'));

        return response()->json([
            'message' => 'Password set successfully.',
            'user' => new AuthUserResource($user),
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
