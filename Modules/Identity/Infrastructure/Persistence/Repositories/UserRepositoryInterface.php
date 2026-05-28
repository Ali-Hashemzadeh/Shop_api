<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Identity\Domain\Models\User;

interface UserRepositoryInterface
{
    public function create(array $attributes): User;

    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    public function findByPhone(string $phone): ?User;

    public function findForLogin(string $login, string $mode = 'both'): ?User;

    public function update(User $user, array $attributes): bool;

    public function refresh(User $user): User;

    public function paginate(int $perPage = 15): LengthAwarePaginator;
}
