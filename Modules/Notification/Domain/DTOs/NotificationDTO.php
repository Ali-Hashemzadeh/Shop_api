<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\DTOs;

use Carbon\Carbon;
use Modules\Notification\Domain\Models\Notification;

/** Immutable view of one stored in-app notification. */
class NotificationDTO
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        public readonly ?array $data,
        public readonly ?Carbon $readAt,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(Notification $notification): self
    {
        return new self(
            id: $notification->id,
            userId: $notification->user_id,
            type: $notification->type,
            title: $notification->title,
            message: $notification->message,
            data: $notification->data,
            readAt: $notification->read_at,
            createdAt: Carbon::parse($notification->created_at),
        );
    }
}
