<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Review\Application\Actions\SyncProductRatingsAction;

class ReviewsSyncProductRatingsCommand extends Command
{
    protected $signature = 'reviews:sync-product-ratings';

    protected $description = 'Aggregate approved+rated reviews per product and sync Catalog rating summaries (mirrors orders:sync-sales-counts).';

    public function handle(SyncProductRatingsAction $action): int
    {
        $count = $action->handle();
        $this->info("Synced rating summaries for {$count} product(s).");

        return Command::SUCCESS;
    }
}
