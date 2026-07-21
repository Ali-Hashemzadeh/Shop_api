<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Workflows\ShipmentWorkflowResolver;

/**
 * Seeds one operational shipment per paid demo order and drives it to the state named
 * in the order's note marker — "[demo] paid order (shipment: handed_to_post)".
 *
 * Nothing is inserted by hand: shipments are created through
 * ShipmentManagerInterface::activateForPaidOrder() (the same call markAsPaid makes) and
 * advanced through ShipmentTransitionService, so every demo shipment ends up with a real
 * status history, a correctly synced order status, a settled slot reservation, and the
 * customer notifications the transition would genuinely have produced.
 *
 * Reads Order — the same direction PaymentSampleDataSeeder reads it, and the direction
 * Shipment already depends in through OrderManagerInterface.
 */
class ShipmentSampleDataSeeder extends Seeder
{
    /** Extra column values that make each state look like real operator input. */
    private const ATTRIBUTES = [
        'handed_to_post' => ['carrier_name' => 'Iran Post', 'tracking_number' => 'IRPOST'],
        'out_for_delivery' => ['carrier_name' => 'In-house courier'],
        'delivered' => ['receiver_name' => 'Received by customer'],
        'delivery_failed' => ['failure_reason' => 'Nobody was home at the delivery time.'],
        'picked_up' => ['receiver_name' => 'Collected at the counter'],
        'cancelled' => ['failure_reason' => 'Cancelled by store operator.'],
    ];

    public function __construct(
        private readonly ShipmentManagerInterface $shipments,
        private readonly ShipmentTransitionService $transitions,
        private readonly ShipmentWorkflowResolver $workflows,
    ) {}

    public function run(): void
    {
        $orders = Order::where('notes', 'like', '[demo]%(shipment: %')->get();

        if ($orders->isEmpty()) {
            $this->command->warn('No demo orders with a shipment marker found — run OrderSampleDataSeeder first.');

            return;
        }

        $activated = 0;
        $advanced = 0;

        foreach ($orders as $order) {
            if (Shipment::where('order_id', $order->id)->exists()) {
                continue;
            }

            $target = $this->targetStatus($order->notes);

            if ($target === null) {
                continue;
            }

            $dto = $this->shipments->activateForPaidOrder(
                $order->id,
                (int) $order->user_id,
                $order->shipment_snapshot ?? [],
            );

            if ($dto === null) {
                $this->command->warn("Order {$order->id} carries no shipment selection — skipped.");

                continue;
            }

            $activated++;
            $advanced += $this->driveTo($dto->id, (string) $order->shipment_snapshot['method_type'], $target);
        }

        $this->command->info("Shipment sample data seeded: {$activated} shipments, {$advanced} status transitions across the postal, local-delivery and pickup workflows.");
    }

    /**
     * Walk the shipment from `pending` to the target, one legal transition at a time.
     * The route is discovered from the workflow itself (breadth-first over its own
     * transition map), so a workflow change reshapes the demo data instead of breaking it.
     *
     * @return int transitions applied
     */
    private function driveTo(int $shipmentId, string $methodType, ShipmentStatus $target): int
    {
        $path = $this->shortestPath($methodType, ShipmentStatus::Pending, $target);

        foreach ($path as $step) {
            $this->transitions->transition(
                shipmentId: $shipmentId,
                to: $step,
                reason: $step === ShipmentStatus::Cancelled ? 'operator_cancelled' : null,
                note: 'Seeded demo transition.',
                attributes: $this->attributesFor($step, $shipmentId),
            );
        }

        return count($path);
    }

    /**
     * @return ShipmentStatus[] the statuses to enter, in order, excluding the start
     */
    private function shortestPath(string $methodType, ShipmentStatus $from, ShipmentStatus $to): array
    {
        if ($from === $to) {
            return [];
        }

        $transitions = $this->workflows->forTypeOrFail($methodType)->transitions();

        $queue = [[$from->value, []]];
        $seen = [$from->value => true];

        while ($queue !== []) {
            [$current, $path] = array_shift($queue);

            foreach ($transitions[$current] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $nextPath = [...$path, ShipmentStatus::from($next)];

                if ($next === $to->value) {
                    return $nextPath;
                }

                $queue[] = [$next, $nextPath];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function attributesFor(ShipmentStatus $status, int $shipmentId): array
    {
        $attributes = self::ATTRIBUTES[$status->value] ?? [];

        // Make the postal tracking number unique per shipment so tracking lookups and the
        // indexed tracking_number column both behave like production data.
        if (isset($attributes['tracking_number'])) {
            $attributes['tracking_number'] .= str_pad((string) $shipmentId, 8, '0', STR_PAD_LEFT);
        }

        return $attributes;
    }

    private function targetStatus(?string $notes): ?ShipmentStatus
    {
        if ($notes === null || preg_match('/\(shipment: ([a-z_]+)\)/', $notes, $matches) !== 1) {
            return null;
        }

        return ShipmentStatus::tryFrom($matches[1]);
    }
}
