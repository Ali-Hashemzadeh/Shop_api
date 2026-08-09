<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notification\Application\Actions\SyncAdminSmsRecipientsAction;
use Modules\Notification\Domain\DTOs\AdminSmsRecipientDTO;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Notification\Infrastructure\Http\Requests\UpdateAdminSmsRecipientsRequest;
use Modules\Notification\Infrastructure\Http\Resources\AdminSmsRecipientResource;

/**
 * Which admins get the paid-order *SMS*.
 *
 * The in-app paid-order notification is not configurable and is not touched
 * here: every admin keeps receiving it. This endpoint only decides who is also
 * worth waking up with a text message.
 */
class AdminNotificationRecipientController extends Controller
{
    public function __construct(
        private readonly SyncAdminSmsRecipientsAction $recipients,
    ) {}

    public function indexOrderPaid(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('notification.admin-sms-recipients.manage'), 403);

        return $this->respond($this->recipients->list(NotificationType::ADMIN_ORDER_PAID));
    }

    public function updateOrderPaid(UpdateAdminSmsRecipientsRequest $request): JsonResponse
    {
        return $this->respond(
            $this->recipients->handle(NotificationType::ADMIN_ORDER_PAID, $request->userIds())
        );
    }

    /**
     * @param  list<AdminSmsRecipientDTO>  $recipients
     */
    private function respond(array $recipients): JsonResponse
    {
        return response()->json(['data' => AdminSmsRecipientResource::collection($recipients)]);
    }
}
