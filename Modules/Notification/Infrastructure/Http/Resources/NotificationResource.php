<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notification\Domain\DTOs\NotificationDTO;

/**
 * Customer-facing shape of an in-app notification. Delivery bookkeeping
 * (provider names, provider references, error strings) is deliberately absent.
 *
 * @mixin NotificationDTO
 */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var NotificationDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'type' => $dto->type,
            'title' => $dto->title,
            'message' => $dto->message,
            'data' => $dto->data,
            'read_at' => $dto->readAt?->toISOString(),
            'created_at' => $dto->createdAt->toISOString(),
        ];
    }
}
