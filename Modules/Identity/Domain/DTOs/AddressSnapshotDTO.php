<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\DTOs;

/**
 * An immutable, already-resolved copy of one address, published for modules that
 * must *freeze* a delivery destination (Shipment's `address_snapshot`).
 *
 * Province and city names are resolved here, inside the module that owns those
 * tables, so no other module joins against them. The map coordinates travel with
 * the postal fields because a courier navigates to the pin, not to the text — but
 * they are a snapshot like everything else: editing the address later never
 * changes where an already-placed delivery was meant to go.
 */
class AddressSnapshotDTO
{
    public function __construct(
        public readonly int $addressId,
        public readonly ?int $provinceId,
        public readonly ?string $provinceName,
        public readonly ?int $cityId,
        public readonly ?string $cityName,
        public readonly ?string $postalCode,
        public readonly ?string $address,
        public readonly ?string $latitude,
        public readonly ?string $longitude,
        public readonly ?string $mapAddress,
    ) {}

    /**
     * The frozen array shape stored on a shipment. Kept here so the snapshot keys
     * are owned by the module that owns the data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'address_id' => $this->addressId,
            'province_id' => $this->provinceId,
            'province_name' => $this->provinceName,
            'city_id' => $this->cityId,
            'city_name' => $this->cityName,
            'postal_code' => $this->postalCode,
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'map_address' => $this->mapAddress,
        ];
    }
}
