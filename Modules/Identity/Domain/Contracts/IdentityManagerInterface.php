<?php

namespace Modules\Identity\Domain\Contracts;

interface IdentityManagerInterface
{
    /**
     * Determine whether the given user holds the administrator role.
     * Called cross-module — never leak the User model across this boundary.
     */
    public function isAdmin(int $userId): bool;
}
