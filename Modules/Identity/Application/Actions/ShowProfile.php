<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;


class ShowProfile
{
    public function __construct(
        private UserRepositoryInterface $users
    ) {
    }

    public function handle(User $user): User
    {
        return $this->users->findById($user->id) ?? $user;
    }
}
