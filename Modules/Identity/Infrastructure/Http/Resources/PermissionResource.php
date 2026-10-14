<?php

namespace Modules\Identity\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

/**
 * One seeded permission. `group` is the namespace before the first dot
 * (`catalog` for `catalog.product.create`), which is what an admin picker
 * groups by. Permissions are module-seeded rows; this endpoint only reads them.
 *
 * @mixin Permission
 */
class PermissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'group' => Str::before($this->name, '.'),
        ];
    }
}
