<?php

namespace Modules\Identity\Domain\Contracts;

use Modules\Identity\Domain\DTOs\UserSummaryDTO;

interface IdentityManagerInterface
{
    /**
     * Determine whether the given user holds the administrator role.
     * Called cross-module — never leak the User model across this boundary.
     */
    public function isAdmin(int $userId): bool;

    /**
     * Return the identity fields another module needs to snapshot (e.g. Order's
     * immutable customer_snapshot). Never leak the User model across this boundary.
     */
    public function getUserSummary(int $userId): UserSummaryDTO;
}
