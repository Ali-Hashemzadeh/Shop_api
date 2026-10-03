<?php

namespace Modules\Identity\Infrastructure\Persistence\Repositories;

use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Identity\Domain\DTOs\AddressSnapshotDTO;
use Modules\Identity\Domain\DTOs\UserSummaryDTO;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\ProvinceNeighbor;
use Modules\Identity\Domain\Models\User;

class EloquentIdentityManager implements IdentityManagerInterface
{
    public function isAdmin(int $userId): bool
    {
        $user = User::find($userId);

        return $user?->hasRole('admin') ?? false;
    }

    public function isDeliveryUser(int $userId): bool
    {
        $user = User::find($userId);

        return $user?->hasRole('delivery') ?? false;
    }

    public function getOwnedAddressSnapshot(int $userId, int $addressId): ?AddressSnapshotDTO
    {
        /** @var Address|null $address */
        $address = Address::with(['province', 'city'])
            ->where('id', $addressId)
            ->where('user_id', $userId)
            ->first();

        if ($address === null) {
            return null;
        }

        return new AddressSnapshotDTO(
            addressId: (int) $address->id,
            provinceId: $address->province_id !== null ? (int) $address->province_id : null,
            provinceName: $address->province?->name,
            cityId: $address->city_id !== null ? (int) $address->city_id : null,
            cityName: $address->city?->name,
            postalCode: $address->postal_code,
            address: $address->address,
            // Decimal casts hand back strings; kept as-is so no float ever rounds a
            // coordinate on its way into an immutable snapshot.
            latitude: $address->latitude !== null ? (string) $address->latitude : null,
            longitude: $address->longitude !== null ? (string) $address->longitude : null,
            mapAddress: $address->map_address,
        );
    }

    public function getUserSummary(int $userId): UserSummaryDTO
    {
        return UserSummaryDTO::fromModel(User::findOrFail($userId));
    }

    public function getProvinceDistanceType(?int $originProvinceId, ?int $destinationProvinceId): string
    {
        // Indeterminate destination/origin defaults to the farthest (safest) band, so
        // a missing coordinate never accidentally prices as "same province".
        if ($originProvinceId === null || $destinationProvinceId === null) {
            return 'non_neighbor';
        }

        if ($originProvinceId === $destinationProvinceId) {
            return 'same_province';
        }

        // Adjacency is matched in either direction, so a row seeded only one way still
        // classifies both origin→destination and destination→origin as neighbours.
        $isNeighbor = ProvinceNeighbor::query()
            ->where(function ($query) use ($originProvinceId, $destinationProvinceId) {
                $query->where('province_id', $originProvinceId)
                    ->where('neighbor_province_id', $destinationProvinceId);
            })
            ->orWhere(function ($query) use ($originProvinceId, $destinationProvinceId) {
                $query->where('province_id', $destinationProvinceId)
                    ->where('neighbor_province_id', $originProvinceId);
            })
            ->exists();

        return $isNeighbor ? 'neighbor' : 'non_neighbor';
    }

    public function getAdminUserIds(): array
    {
        return User::role('admin')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function getAdminUserSummaries(): array
    {
        return User::role('admin')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => UserSummaryDTO::fromModel($user))
            ->all();
    }

    public function getDeliveryUserIds(): array
    {
        return User::role('delivery')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
