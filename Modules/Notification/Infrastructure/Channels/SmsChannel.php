<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Channels;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Notification\Domain\Contracts\NotificationChannelInterface;
use Modules\Notification\Domain\DTOs\NotificationRequestDTO;
use Modules\Notification\Domain\Enums\DeliveryStatus;
use Modules\Notification\Domain\Enums\NotificationChannel;
use Modules\Notification\Domain\Models\NotificationDelivery;
use Modules\Sms\Domain\Contracts\SmsManagerInterface;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Domain\DTOs\SmsResultDTO;

/**
 * SMS channel: turns a notification request into the Sms module's internal
 * SmsMessageDTO and records the outcome as a delivery row.
 *
 * It knows nothing about SMS providers — that is SmsManager's job — and it
 * resolves the recipient's phone through Identity's contract, never through
 * the User model.
 */
class SmsChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly SmsManagerInterface $sms,
        private readonly IdentityManagerInterface $identity,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::SMS;
    }

    public function send(NotificationRequestDTO $request, ?int $notificationId = null): ?int
    {
        if ($request->sms === null) {
            // Caller asked for SMS but supplied no template — a programming
            // error, not a configuration gap, so it stays a failure.
            $this->record($notificationId, DeliveryStatus::FAILED, $this->sms->providerName(), null,
                "No SMS payload supplied for notification type [{$request->type}].");

            return null;
        }

        $phone = $this->resolvePhone($request->userId);

        if ($phone === null || $phone === '') {
            // Nothing is wrong with the system — this customer simply has no
            // number on file, so the message is skipped, not failed.
            $this->skip($notificationId, $request, "Recipient [{$request->userId}] has no phone number.");

            return null;
        }

        $result = $this->sms->send(new SmsMessageDTO(
            receiver: $phone,
            template: $request->sms->template->value,
            parameters: $request->sms->parameters,
        ));

        $this->record(
            $notificationId,
            $this->statusFor($result),
            $result->provider,
            $result->reference,
            $result->error,
        );

        return null;
    }

    /**
     * A skipped send (no provider template id configured, no credentials) is
     * recorded as SKIPPED so it never looks like a delivery incident.
     */
    private function statusFor(SmsResultDTO $result): DeliveryStatus
    {
        return match (true) {
            $result->success => DeliveryStatus::SENT,
            $result->skipped => DeliveryStatus::SKIPPED,
            default => DeliveryStatus::FAILED,
        };
    }

    private function skip(?int $notificationId, NotificationRequestDTO $request, string $reason): void
    {
        Log::info('[Notification] SMS skipped', [
            'type' => $request->type,
            'user_id' => $request->userId,
            'reason' => $reason,
        ]);

        $this->record($notificationId, DeliveryStatus::SKIPPED, $this->sms->providerName(), null, $reason);
    }

    private function resolvePhone(int $userId): ?string
    {
        try {
            return $this->identity->getUserSummary($userId)->phone;
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    private function record(
        ?int $notificationId,
        DeliveryStatus $status,
        string $provider,
        ?string $reference,
        ?string $error,
    ): void {
        NotificationDelivery::create([
            'notification_id' => $notificationId,
            'channel' => NotificationChannel::SMS->value,
            'status' => $status->value,
            'provider' => $provider,
            'provider_reference' => $reference,
            'sent_at' => $status === DeliveryStatus::SENT ? now() : null,
            'failed_at' => $status === DeliveryStatus::FAILED ? now() : null,
            'error' => $error,
        ]);
    }
}
