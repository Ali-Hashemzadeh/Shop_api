<?php

declare(strict_types=1);

namespace Modules\Notification\Domain\DTOs;

use Modules\Notification\Domain\Enums\NotificationChannel;

/**
 * A request to notify one user, addressed to one or more channels.
 *
 * The caller owns the business meaning (`type`, the human-readable title and
 * message, the `data` payload, and which channels apply); this module owns
 * persistence and delivery. No business copy or channel policy lives inside
 * the Notification module itself.
 *
 * `sms` is required only when NotificationChannel::SMS is requested — the SMS
 * body is template-driven and therefore cannot be derived from `message`.
 */
class NotificationRequestDTO
{
    /**
     * @param  array<string, mixed>|null  $data
     * @param  list<NotificationChannel>  $channels
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        public readonly ?array $data = null,
        public readonly array $channels = [NotificationChannel::DATABASE],
        public readonly ?SmsPayloadDTO $sms = null,
    ) {}

    public function hasChannel(NotificationChannel $channel): bool
    {
        return in_array($channel, $this->channels, true);
    }
}
