<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Modules\Identity\Domain\Models\User;
use Modules\Identity\Domain\Repositories\UserRepositoryInterface;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()
            ->where('email', $email)
            ->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return User::query()
            ->where('phone', $phone)
            ->first();
    }

    public function findForLogin(string $login, string $mode = 'both'): ?User
    {
        $login = trim($login);

        return User::query()
            ->when($mode === 'email', fn ($query) => $query->where('email', $login))
            ->when($mode === 'phone', fn ($query) => $query->where('phone', $login))
            ->when($mode === 'both', function ($query) use ($login) {
                $query->where(function ($innerQuery) use ($login) {
                    $innerQuery->where('email', $login)
                        ->orWhere('phone', $login);
                });
            })
            ->first();
    }

    public function update(User $user, array $attributes): bool
    {
        return $user->update($attributes);
    }
}
