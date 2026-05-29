<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Collection;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;

interface AddressRepositoryInterface
{
    public function listForUser(User $user): Collection;

    public function createForUser(User $user, array $attributes): Address;

    public function update(Address $address, array $attributes): bool;

    public function delete(Address $address): bool;

    public function clearDefaultShippingForUser(User $user): int;

    public function findById(int $id): ?Address;

    public function refreshWithRelations(Address $address): Address;

}
