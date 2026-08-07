<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class ShowCustomerAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    public function handle(string $publicCode): Address
    {
        $address = $this->addresses->findByPublicCode($publicCode);

        if ($address === null) {
            throw (new ModelNotFoundException)->setModel(Address::class, [$publicCode]);
        }

        return $this->addresses->refreshWithRelations($address);
    }
}
