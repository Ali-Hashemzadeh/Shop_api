<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Collection;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;

interface AddressRepositoryInterface
{
    /**
     * The user's own addresses.
     *
     * $search is an optional exact `bda-XXXXXX` public code (case-insensitive).
     * The user scope is applied unconditionally and is never widened by it, so
     * another user's code yields an empty list rather than their address.
     */
    public function listForUser(User $user, ?string $search = null): Collection;

    public function createForUser(User $user, array $attributes): Address;

    public function update(Address $address, array $attributes): bool;

    public function delete(Address $address): bool;

    public function clearDefaultShippingForUser(User $user): int;

    public function findById(int $id): ?Address;

    public function refreshWithRelations(Address $address): Address;
}
