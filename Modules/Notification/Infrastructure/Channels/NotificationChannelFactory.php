<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Modules\Notification\Domain\Contracts\NotificationChannelInterface;
use Modules\Notification\Domain\Enums\NotificationChannel;

/**
 * Resolves a channel enum to its implementation. Mirrors the Payment module's
 * PaymentGatewayFactory so a new channel is one map entry plus one class.
 */
class NotificationChannelFactory
{
    /** @var array<string, class-string<NotificationChannelInterface>> */
    private array $channels = [
        NotificationChannel::DATABASE->value => DatabaseChannel::class,
        NotificationChannel::SMS->value => SmsChannel::class,
    ];

    public function make(NotificationChannel $channel): NotificationChannelInterface
    {
        return app($this->channels[$channel->value]);
    }

    /** @param  class-string<NotificationChannelInterface>  $channelClass */
    public function register(NotificationChannel $channel, string $channelClass): void
    {
        $this->channels[$channel->value] = $channelClass;
    }
}
