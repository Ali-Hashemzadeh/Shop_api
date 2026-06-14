<?php

namespace Modules\Media\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class MediaPolicy
{
    public function upload(Authorizable $user): bool
    {
        return $user->can('media.upload');
    }

    public function delete(Authorizable $user): bool
    {
        return $user->can('media.delete');
    }
}
