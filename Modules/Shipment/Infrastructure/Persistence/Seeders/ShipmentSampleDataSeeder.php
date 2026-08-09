<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Order\Domain\Models\Order;
use Modules\Shipment\Application\Actions\AssignShipmentDeliveryAction;
use Modules\Shipment\Application\Actions\MarkShipmentDeliveredAction;
use Modules\Shipment\Application\Services\ShipmentTransitionService;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Enums\ShipmentStatus;
use Modules\Shipment\Domain\Events\ShipmentOutForDeliveryEvent;
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
        private readonly IdentityManagerInterface $identity,
        private readonly AssignShipmentDeliveryAction $assignDelivery,
        private readonly MarkShipmentDeliveredAction $markDelivered,
    ) {}

    /**
     * Handoff codes overheard from the dispatch event, keyed by order id.
     *
     * This seeder stands in for the customer's inbox: the plaintext code exists
     * only on ShipmentOutForDeliveryEvent and in the SMS, never in the database, so listening
     * for it is the *only* way a demo delivery can be completed — exactly as a real
     * courier can only be let in by a customer reading their text message out.
     *
     * @var array<int, string>
     */
    private array $capturedCodes = [];

    public function run(): void
    {
        $this->listenForDeliveryCodes();

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
     * The two local-delivery rules are honoured rather than worked around: a driver
     * is assigned through the real assignment action before dispatch, and delivery
     * is closed through the real completion action with the real handoff code —
     * captured from the dispatch event exactly as the customer captures it from
     * their SMS. Demo data that skipped either would be demo data that cannot
     * happen in production.
     *
     * @return int transitions applied
     */
    private function driveTo(int $shipmentId, string $methodType, ShipmentStatus $target): int
    {
        $path = $this->shortestPath($methodType, ShipmentStatus::Pending, $target);
        $isLocal = $methodType === ShipmentMethodType::LocalDelivery->value;

        if ($isLocal && $this->pathReaches($path, ShipmentStatus::OutForDelivery)) {
            $this->assignDemoDriver($shipmentId);
        }

        foreach ($path as $step) {
            if ($isLocal && $step === ShipmentStatus::Delivered) {
                $shipment = Shipment::findOrFail($shipmentId);

                $this->markDelivered->handle(
                    shipmentId: $shipmentId,
                    operatorId: (int) $shipment->assigned_delivery_user_id,
                    receiverName: self::ATTRIBUTES['delivered']['receiver_name'] ?? null,
                    note: 'Seeded demo transition.',
                    code: $this->capturedCodes[$shipment->order_id] ?? null,
                    mustBeAssignedTo: (int) $shipment->assigned_delivery_user_id,
                );

                continue;
            }

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
     * Added once for the whole run and never removed — removing it would take the
     * real notification listeners for the same event down with it.
     */
    private function listenForDeliveryCodes(): void
    {
        Event::listen(ShipmentOutForDeliveryEvent::class, function (ShipmentOutForDeliveryEvent $event): void {
            if ($event->deliveryCode !== null) {
                $this->capturedCodes[$event->orderId] = $event->deliveryCode;
            }
        });
    }

    /** @param ShipmentStatus[] $path */
    private function pathReaches(array $path, ShipmentStatus $status): bool
    {
        return in_array($status, $path, true);
    }

    /**
     * Assign the first configured delivery worker through the production action,
     * so the demo shipment carries a real assignment history row and produces the
     * same courier notification a real assignment would.
     */
    private function assignDemoDriver(int $shipmentId): void
    {
        $driverId = $this->identity->getDeliveryUserIds()[0] ?? null;

        if ($driverId === null) {
            $this->command->warn('No delivery worker exists — run the Identity seeders first.');

            return;
        }

        $assignedBy = $this->identity->getAdminUserIds()[0] ?? $driverId;

        $this->assignDelivery->handle($shipmentId, $driverId, $assignedBy);
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
