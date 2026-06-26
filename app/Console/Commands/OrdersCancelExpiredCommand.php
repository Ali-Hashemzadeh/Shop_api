<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Order\Application\Actions\CancelExpiredOrdersAction;

class OrdersCancelExpiredCommand extends Command
{
    protected $signature = 'orders:cancel-expired';

    protected $description = 'Cancel pending orders that have exceeded the 15-minute checkout window and release their inventory reservations.';

    public function handle(CancelExpiredOrdersAction $action): int
    {
        $count = $action->handle();
        $this->info("Cancelled {$count} expired pending order(s).");

        return Command::SUCCESS;
    }
}
