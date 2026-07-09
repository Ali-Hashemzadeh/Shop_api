<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\Hash;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\UserRepositoryInterface;

/**
 * Set (or replace) the password on an already-authenticated account. Ownership
 * is proven by the Sanctum token, so no current password is required. The value
 * is hashed before it is persisted.
 */
class SetPassword
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {}

    public function handle(User $user, string $password): User
    {
        $this->users->update($user, [
            'password' => Hash::make($password),
        ]);

        return $this->users->refresh($user);
    }
}
