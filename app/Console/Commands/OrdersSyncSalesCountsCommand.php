<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Order\Application\Actions\SyncSalesCountsAction;

class OrdersSyncSalesCountsCommand extends Command
{
    protected $signature = 'orders:sync-sales-counts';

    protected $description = 'Recompute per-product units-sold from realized orders and sync Catalog best-seller counters.';

    public function handle(SyncSalesCountsAction $action): int
    {
        $count = $action->handle();
        $this->info("Synced sales counts for {$count} SKU(s).");

        return Command::SUCCESS;
    }
}
