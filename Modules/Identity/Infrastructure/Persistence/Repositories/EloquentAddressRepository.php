<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Support\Collection;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;

class EloquentAddressRepository implements AddressRepositoryInterface
{
    public function listForUser(User $user, ?string $search = null): Collection
    {
        $query = Address::query()
            ->where('user_id', $user->id)
            ->with(['province', 'city'])
            ->orderByDesc('is_default_shipping')
            ->latest('id');

        $search = $search === null ? '' : trim($search);

        if ($search !== '') {
            // The user_id constraint above is unconditional and is never relaxed by
            // the search term, so another user's code returns an empty list —
            // indistinguishable from a code that does not exist. Exact equality on
            // the unique index only; a partial code must never match.
            $query->where(
                'public_code',
                PublicCodeGenerator::matches($search, PublicCodeEntity::Address)
                    ? PublicCodeGenerator::normalize($search)
                    : $search,
            );
        }

        return $query->get();
    }

    public function createForUser(User $user, array $attributes): Address
    {
        // Server-owned identifier: never accept one from the caller's payload.
        unset($attributes['public_code']);
        $attributes['user_id'] = $user->id;

        return Address::createWithPublicCode($attributes);
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

    public function findByPublicCode(string $publicCode): ?Address
    {
        if (! PublicCodeGenerator::matches($publicCode, PublicCodeEntity::Address)) {
            return null;
        }

        return Address::query()
            ->where('public_code', PublicCodeGenerator::normalize($publicCode))
            ->first();
    }

    public function refreshWithRelations(Address $address): Address
    {
        return $address->load(['province', 'city']);
    }
}
