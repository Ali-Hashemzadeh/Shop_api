<?php

declare(strict_types=1);

namespace Modules\Notification\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Notification\Domain\DTOs\AdminSmsRecipientDTO;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Enums\NotificationType;
use Modules\Notification\Infrastructure\Persistence\Repositories\RecipientPreferenceRepositoryInterface;

/**
 * Read and replace the set of admins who receive a given notification by SMS.
 *
 * Admin-ness is asked of Identity's contract rather than checked with an
 * `exists:users,id` rule, because Notification does not query Identity's tables
 * — and because "this id exists" is the wrong question: a customer id exists too.
 */
class SyncAdminSmsRecipientsAction
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
        private readonly RecipientPreferenceRepositoryInterface $preferences,
    ) {}

    /**
     * Every admin, each flagged with whether they are currently selected.
     *
     * The full roster is returned, not just the selected ones, so the picker can
     * be rendered from a single call.
     *
     * @return list<AdminSmsRecipientDTO>
     */
    public function list(NotificationType $type): array
    {
        $enabled = array_flip($this->preferences->enabledUserIds($type, NotificationChannel::SMS));

        return array_map(
            fn ($admin) => new AdminSmsRecipientDTO(
                userId: $admin->id,
                name: $admin->name,
                lastName: $admin->lastName,
                phone: $admin->phone,
                enabled: isset($enabled[$admin->id]),
            ),
            $this->identity->getAdminUserSummaries(),
        );
    }

    /**
     * @param  list<int>  $userIds  the admins that should be selected afterwards
     * @return list<AdminSmsRecipientDTO>
     *
     * @throws ValidationException when any id does not name an administrator
     */
    public function handle(NotificationType $type, array $userIds): array
    {
        $this->assertAllAdmins($userIds);

        $this->preferences->syncEnabled($type, NotificationChannel::SMS, $userIds);

        return $this->list($type);
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertAllAdmins(array $userIds): void
    {
        $errors = [];

        foreach ($userIds as $index => $userId) {
            if (! $this->identity->isAdmin((int) $userId)) {
                $errors["user_ids.{$index}"] = ["User [{$userId}] is not an administrator."];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
