<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\DTOs;

use Modules\Identity\Domain\Models\User;

class UserSummaryDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly ?string $lastName,
        public readonly ?string $phone,
        public readonly ?string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            lastName: $user->last_name,
            phone: $user->phone,
            email: $user->email,
        );
    }
}
