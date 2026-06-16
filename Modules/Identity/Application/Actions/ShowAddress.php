<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class ShowAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    public function handle(Address $address): Address
    {
        return $this->addresses->refreshWithRelations($address);
    }
}
