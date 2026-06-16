<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class UpdateAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    public function handle(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data) {
            if (($data['is_default_shipping'] ?? false) === true) {
                $this->addresses->clearDefaultShippingForUser($address->user);
            }

            $this->addresses->update($address, $data);

            return $this->addresses->refreshWithRelations($address);
        });
    }
}
