<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Payment\Application\Actions\ExpireStalePaymentsAction;

class PaymentsExpireStaleCommand extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Expire INITIATED payments older than 30 minutes by verifying with the gateway; recovers any that were captured but whose callback was lost.';

    public function handle(ExpireStalePaymentsAction $action): int
    {
        $result = $action->handle();

        $this->info("Expired {$result['expired']} stale payment(s).");

        if ($result['recovered'] > 0) {
            $this->warn("Recovered {$result['recovered']} payment(s) that were captured but whose callback was lost.");
        }

        return Command::SUCCESS;
    }
}
