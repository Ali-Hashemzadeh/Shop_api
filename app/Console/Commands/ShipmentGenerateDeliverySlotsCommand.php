<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Shipment\Application\Actions\GenerateDeliverySlotsAction;

class ShipmentGenerateDeliverySlotsCommand extends Command
{
    protected $signature = 'shipment:generate-delivery-slots {--days= : Number of days ahead to generate}';

    protected $description = 'Generate dated local-delivery sessions from recurring working periods. Idempotent and safe to run daily.';

    public function handle(GenerateDeliverySlotsAction $action): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $created = $action->handle($days);

        $this->info("Generated {$created} new delivery slot(s).");

        return Command::SUCCESS;
    }
}
