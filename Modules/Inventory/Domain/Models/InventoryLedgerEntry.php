<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryLedgerEntry extends Model
{
    // Append-only audit log — rows are never mutated after insert.
    const UPDATED_AT = null;

    protected $table = 'inventory_ledger_entries';

    protected $fillable = [
        'sku',
        'type',
        'quantity_change',
        'reference_type',
        'reference_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'reference_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
