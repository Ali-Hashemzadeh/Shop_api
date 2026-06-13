<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Identity\Domain\Models\User;

class EloquentIdentityManager implements IdentityManagerInterface
{
    public function isAdmin(int $userId): bool
    {
        $user = User::find($userId);

        return $user?->hasRole('admin') ?? false;
    }
}
