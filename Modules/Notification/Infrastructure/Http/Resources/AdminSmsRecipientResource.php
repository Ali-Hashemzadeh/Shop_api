<?php

declare(strict_types=1);

namespace Modules\Notification\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Notification\Domain\DTOs\AdminSmsRecipientDTO;

/**
 * @mixin AdminSmsRecipientDTO
 */
class AdminSmsRecipientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AdminSmsRecipientDTO $dto */
        $dto = $this->resource;

        return [
            'user_id' => $dto->userId,
            'name' => $dto->name,
            'last_name' => $dto->lastName,
            // Shown so an operator can tell two same-named admins apart, and can see
            // at a glance that a selected admin has no number to text.
            'phone' => $dto->phone,
            'enabled' => $dto->enabled,
        ];
    }
}
