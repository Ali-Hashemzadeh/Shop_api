<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Persistence\Repositories\AddressRepositoryInterface;

class SetDefaultShippingAddress
{
    public function __construct(
        private readonly AddressRepositoryInterface $addresses
    ) {}

    public function handle(User $user, Address $address): Address
    {
        return DB::transaction(function () use ($user, $address) {
            $this->addresses->clearDefaultShippingForUser($user);

            $this->addresses->update($address, [
                'is_default_shipping' => true,
            ]);

            return $this->addresses->refreshWithRelations($address);
        });
    }
}
