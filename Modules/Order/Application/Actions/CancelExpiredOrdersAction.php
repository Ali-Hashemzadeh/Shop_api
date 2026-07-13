<?php

declare(strict_types=1);

namespace Modules\Order\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Models\Order;

class CancelExpiredOrdersAction
{
    public function __construct(
        private readonly CancelOrderAction $cancelOrder,
    ) {}

    public function handle(): int
    {
        $cutoff = now()->subMinutes(15);
        $count = 0;

        Order::with('items')
            ->where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->each(function (Order $order) use (&$count) {
                DB::transaction(fn () => $this->cancelOrder->releaseAndCancel($order));
                $count++;
            });

        return $count;
    }
}
