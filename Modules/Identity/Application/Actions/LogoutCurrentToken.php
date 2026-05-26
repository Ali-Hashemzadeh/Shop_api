<?php

namespace Modules\Identity\Application\Actions;

use Modules\Identity\Domain\Models\User;

class LogoutCurrentToken
{
    public function handle(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
