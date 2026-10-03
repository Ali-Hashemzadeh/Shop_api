<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Ticket\Infrastructure\Http\Requests\SupportUsersRequest;
use Modules\Ticket\Infrastructure\Http\Resources\SupportUserResource;

/**
 * Admin management of who the support agents are (`ticket.manage-support-users`).
 *
 * All role work goes through the Identity contract — this controller never
 * imports the User model or touches the roles tables directly. Granting/revoking
 * is additive and idempotent (handled inside the manager), so the same call may
 * be repeated safely.
 */
class AdminSupportUserController extends Controller
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
    ) {}

    /** The "assign to" picker: every current support agent. */
    public function index(SupportUsersRequest $request): JsonResponse
    {
        return response()->json([
            'data' => SupportUserResource::collection($this->identity->getSupportUserSummaries()),
        ]);
    }

    /** POST /admin/users/{user}/roles/support — add the support role. */
    public function grant(SupportUsersRequest $request, int $user): JsonResponse
    {
        $summary = $this->identity->grantSupportRole($user);

        return response()->json([
            'message' => 'Support role granted successfully.',
            'data' => new SupportUserResource($summary),
        ]);
    }

    /** DELETE /admin/users/{user}/roles/support — remove the support role. */
    public function revoke(SupportUsersRequest $request, int $user): JsonResponse
    {
        $summary = $this->identity->revokeSupportRole($user);

        return response()->json([
            'message' => 'Support role removed successfully.',
            'data' => new SupportUserResource($summary),
        ]);
    }
}
