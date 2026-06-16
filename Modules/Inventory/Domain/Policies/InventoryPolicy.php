<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;

class InventoryPolicy
{
    public function manage(Authorizable $user): bool
    {
        return $user->can('inventory.stock.manage');
    }

    public function viewLedger(Authorizable $user): bool
    {
        return $user->can('inventory.ledger.view');
    }
}
