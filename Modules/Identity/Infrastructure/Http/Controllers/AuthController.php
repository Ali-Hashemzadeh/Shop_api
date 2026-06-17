<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Actions\LogoutCurrentToken;
use Modules\Identity\Application\Actions\RequestOtp;
use Modules\Identity\Application\Actions\VerifyOtp;
use Modules\Identity\Infrastructure\Http\Requests\RequestOtpRequest;
use Modules\Identity\Infrastructure\Http\Requests\VerifyOtpRequest;
use Modules\Identity\Infrastructure\Http\Resources\AuthUserResource;

class AuthController extends Controller
{
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
