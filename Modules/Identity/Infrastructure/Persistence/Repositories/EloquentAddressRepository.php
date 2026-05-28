<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Modules\Identity\Domain\Models\User;
use Illuminate\Support\Collection;
use Modules\Identity\Domain\Models\Address;


class EloquentAddressRepository implements AddressRepositoryInterface
{
    public function listForUser(User $user): Collection
    {
        return Address::query()
            ->where('user_id', $user->id)
            ->with(['province', 'city'])
            ->orderByDesc('is_default_shipping')
            ->latest('id')
            ->get();
    }

    public function createForUser(User $user, array $attributes): Address
    {
        $attributes['user_id'] = $user->id;

        return Address::query()->create($attributes);
    }

    public function update(Address $address, array $attributes): bool
    {
        return $address->update($attributes);
    }

    public function delete(Address $address): bool
    {
        return (bool) $address->delete();
    }

    public function clearDefaultShippingForUser(User $user): int
    {
        return Address::query()
            ->where('user_id', $user->id)
            ->update(['is_default_shipping' => false]);
    }

    public function findById(int $id): ?Address
    {
        return Address::query()->find($id);
    }

    public function refreshWithRelations(Address $address): Address
    {
        return $address->fresh(['province', 'city']);
    }
}
