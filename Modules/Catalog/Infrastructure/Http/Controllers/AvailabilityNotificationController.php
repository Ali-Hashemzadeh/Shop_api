<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Catalog\Application\Actions\CancelAvailabilityNotificationAction;
use Modules\Catalog\Application\Actions\RequestAvailabilityNotificationAction;

/**
 * Request / cancel a "notify me when available" alert for one variant SKU.
 *
 * Self-service (authentication only, no permission). The subscription is tied
 * to the exact SKU and to the authenticated caller; a user_id is never accepted
 * from the client. Both operations are idempotent.
 */
class AvailabilityNotificationController extends Controller
{
    public function __construct(
        private readonly RequestAvailabilityNotificationAction $requestAction,
        private readonly CancelAvailabilityNotificationAction $cancelAction,
    ) {}

    public function store(Request $request, string $sku): JsonResponse
    {
        $this->requestAction->handle((int) $request->user()->id, $sku);

        return response()->json(['availability_notification_requested' => true]);
    }

    public function destroy(Request $request, string $sku): JsonResponse
    {
        $this->cancelAction->handle((int) $request->user()->id, $sku);

        return response()->json(['availability_notification_requested' => false]);
    }
}
