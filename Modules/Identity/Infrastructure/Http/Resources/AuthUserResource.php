<?php

namespace Modules\Identity\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            // Additive capability information: the app needs to know it is talking
            // to a delivery worker to show the driver surface at all. Permissions
            // travel alongside because authorization here is permission-based —
            // a role name alone does not tell the client what it may do.
            'roles' => $this->roles->pluck('name')->values(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'created_at' => $this->created_at,
        ];
    }
}
