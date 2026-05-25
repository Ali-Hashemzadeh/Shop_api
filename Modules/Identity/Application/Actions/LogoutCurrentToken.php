<?php

namespace Modules\Identity\Application\Actions;

use Illuminate\Contracts\Auth\Authenticatable;

class LogoutCurrentToken
{
    public function handle(Authenticatable $user): void
    {
        $user->currentAccessToken()?->delete();
    }
}
