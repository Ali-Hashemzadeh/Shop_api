<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Domain\Contracts\NotificationManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Notification\Infrastructure\Http\Resources\NotificationResource;

/**
 * Customer notification surface. Listing is scoped to the caller, and marking
 * as read is guarded by NotificationPolicy — another user's notification
 * yields 403, never a mutation.
 */
class NotificationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly NotificationManagerInterface $manager,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Notification::class);

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginator = $this->manager->getUserNotifications($request->user()->id, $perPage);

        return response()->json(
            NotificationResource::collection($paginator)->response()->getData(true)
        );
    }

    public function markAsRead(Request $request, int $notification): JsonResponse
    {
        $model = Notification::findOrFail($notification);

        $this->authorize('markRead', $model);

        $dto = $this->manager->markAsRead($notification, $request->user()->id);

        return response()->json(new NotificationResource($dto));
    }
}
