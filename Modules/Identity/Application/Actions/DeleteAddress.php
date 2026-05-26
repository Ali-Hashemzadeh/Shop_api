<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;


class DeleteAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {
    }

    public function handle(Address $address): void
    {
        $this->addresses->delete($address);
    }
}
