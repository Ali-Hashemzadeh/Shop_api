<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Identity\Domain\DTOs\UserSummaryDTO;

/**
 * A support agent for the admin "assign to" picker. Accepts Identity's
 * UserSummaryDTO — Ticket resolves support users only through the Identity
 * contract and never touches the User model.
 *
 * @mixin UserSummaryDTO
 */
class SupportUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var UserSummaryDTO $dto */
        $dto = $this->resource;

        return [
            'id' => $dto->id,
            'name' => $dto->name,
            'last_name' => $dto->lastName,
            'phone' => $dto->phone,
        ];
    }
}
