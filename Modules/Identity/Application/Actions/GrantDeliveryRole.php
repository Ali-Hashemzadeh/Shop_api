<?php

declare(strict_types=1);

namespace Modules\Identity\Application\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Identity\Domain\Models\User;

/**
 * Make an existing account a delivery worker.
 *
 * Additive by construction: roles are *added*, never synced, so an account keeps
 * everything it already had. `customer` is ensured alongside `delivery` because a
 * courier is still a shopper — and because the `delivery` role carries only the
 * extra fulfillment permissions, never a copy of the customer bundle.
 *
 * Idempotent: assignRole() on a role the user already holds is a no-op, so
 * calling this twice changes nothing.
 */
class GrantDeliveryRole
{
    public function handle(User $user): User
    {
        // A courier without a phone number cannot be reached with an assignment
        // SMS and cannot sign in through the OTP flow, so the responsibility is
        // refused rather than granted into a dead end.
        if ($user->phone === null || $user->phone === '') {
            throw ValidationException::withMessages([
                'phone' => ['A delivery worker must have a phone number.'],
            ]);
        }

        if (! $user->hasRole('customer')) {
            $user->assignRole('customer');
        }

        if (! $user->hasRole('delivery')) {
            $user->assignRole('delivery');
        }

        return $user->fresh();
    }
}
