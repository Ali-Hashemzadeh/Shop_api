<?php

namespace Modules\Order\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Cart\Domain\Models\Cart;
use Modules\Cart\Domain\Models\CartItem;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Catalog\Domain\Models\ProductVariant;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Order\Application\Actions\SyncSalesCountsAction;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Shipment\Domain\Contracts\ShipmentManagerInterface;

/**
 * Seeds carts + orders + order items and drives the real Inventory reservation
 * lifecycle (reserve → commit / release) so the inventory ledger is an accurate
 * record of what the orders actually did. Payments are seeded separately by the
 * Payment module's PaymentSampleDataSeeder, keyed off the order status + the
 * transaction_ref prefix written here (CASH- = in-person, REF- = online).
 *
 * Fulfillment is seeded separately too, by the Shipment module's
 * ShipmentSampleDataSeeder. Each order carries a real, validated shipment selection
 * (resolved through ShipmentManagerInterface, exactly like checkout) plus a
 * "shipment: <status>" marker in its notes naming the state that seeder should drive
 * the shipment to — the same keyed-off-the-order trick PaymentSampleDataSeeder uses.
 */
class OrderSampleDataSeeder extends Seeder
{
    private InventoryManagerInterface $inventory;

    private CatalogManagerInterface $catalog;

    private ShipmentManagerInterface $shipment;

    /** @var array<int, ProductVariant> */
    private array $variants = [];

    /** Bookable slot ids, cycled so no single session is overbooked. @var int[]|null */
    private ?array $slotIds = null;

    private int $slotCursor = 0;

    public function run(): void
    {
        if (Order::where('notes', 'like', '[demo]%')->exists()) {
            $this->command->info('Order sample data already present — skipping.');

            return;
        }

        $this->variants = ProductVariant::with('product')->orderBy('id')->get()->values()->all();

        if (count($this->variants) < 6) {
            $this->command->warn('Not enough product variants found — run CatalogSampleDataSeeder + InventorySampleDataSeeder first.');

            return;
        }

        $this->inventory = app(InventoryManagerInterface::class);
        $this->catalog = app(CatalogManagerInterface::class);
        $this->shipment = app(ShipmentManagerInterface::class);

        [$sara, $reza, $nima] = $this->demoCustomers();

        // Active carts (not converted to orders) so cart data is visible in the API.
        $this->seedActiveCarts($sara);

        // Variant index map (ordered by id): 0-2 Galaxy S25 (128/256/512), 3-4 iPhone 16
        // (128/256), 5-6 MacBook Pro 14 (M4/M4 Pro), 7 AirPods Pro 2, 8-9 USB-C Hub (grey/silver).
        // Blueprint: [customer, status, paymentMethod, daysAgo, lines[[variantIndex, qty]],
        //             shipmentMethodCode, targetShipmentStatus|null].
        // Quantities stay small so no SKU is over-reserved against its 50-unit opening stock.
        //
        // Every paid order names the shipment state ShipmentSampleDataSeeder must drive it
        // to, so the three workflows are covered end to end. Order status is left at 'paid'
        // here — the shipment transitions are what move it to processing/shipped/completed,
        // exactly as they do in production via ShipmentStatus::toOrderStatus().
        //
        // Local-delivery orders stay recent (0-2 days) because their booked session is a
        // real, future slot; backdating them further would read as a delivery in the past.
        $blueprints = [
            // ── Postal workflow (pending → preparing → ready_for_post → handed_to_post) ──
            [$sara, 'paid', 'online', 9,  [[0, 1], [7, 2]], 'post_standard',    'pending'],
            [$reza, 'paid', 'online', 7,  [[1, 1]],         'post_standard',    'preparing'],
            [$nima, 'paid', 'online', 6,  [[3, 1]],         'post_express',     'ready_for_post'],
            [$reza, 'paid', 'online', 20, [[3, 1]],         'post_standard',    'handed_to_post'],
            [$sara, 'paid', 'online', 15, [[8, 1]],         'post_express',     'cancelled'],

            // ── Local delivery (pending → preparing → ready_for_dispatch → out_for_delivery
            //    → delivered / delivery_failed), each booking its own session ──
            [$sara, 'paid', 'online', 0,  [[7, 1]],         'local_delivery',   'pending'],
            [$reza, 'paid', 'online', 0,  [[2, 1]],         'local_delivery',   'preparing'],
            [$nima, 'paid', 'online', 1,  [[5, 1], [7, 1]], 'local_delivery',   'ready_for_dispatch'],
            [$sara, 'paid', 'online', 1,  [[9, 1]],         'local_delivery',   'out_for_delivery'],
            [$reza, 'paid', 'online', 2,  [[4, 1]],         'local_delivery',   'delivered'],
            [$nima, 'paid', 'online', 1,  [[8, 2]],         'local_delivery',   'delivery_failed'],
            [$sara, 'paid', 'online', 2,  [[6, 1]],         'local_delivery',   'cancelled'],

            // ── Pickup (pending → preparing → ready_for_pickup → picked_up), paid at the
            //    counter so these carry a CASH- ref + pending_cash payment ──
            [$nima, 'paid', 'in_person', 12, [[9, 2], [8, 1]], 'in_person_pickup', 'pending'],
            [$sara, 'paid', 'in_person', 8,  [[0, 1]],         'in_person_pickup', 'preparing'],
            [$reza, 'paid', 'in_person', 4,  [[7, 1]],         'in_person_pickup', 'ready_for_pickup'],
            [$nima, 'paid', 'in_person', 10, [[1, 1]],         'in_person_pickup', 'picked_up'],

            // ── Never paid → no shipment is ever activated ──
            // Open order: inventory reservation AND delivery-slot hold both still held.
            [$reza, 'pending',   'online', 0, [[5, 1]], 'local_delivery', null],
            // Abandoned / failed — reservations released, no physical stock change.
            [$sara, 'cancelled', 'online', 3, [[3, 1]], 'post_standard',  null],
            [$nima, 'failed',    'online', 1, [[6, 1]], 'post_express',   null],
        ];

        foreach ($blueprints as [$user, $status, $method, $daysAgo, $lines, $shipmentMethod, $shipmentState]) {
            $this->seedOrder($user, $status, $method, $daysAgo, $lines, $shipmentMethod, $shipmentState);
        }

        // Populate Catalog products.sales_count from the realized orders just seeded,
        // so ?sort=most_sold has data immediately (same path the hourly command runs).
        app(SyncSalesCountsAction::class)->handle();

        $this->command->info('Order sample data seeded: '.count($blueprints).' orders + active carts, inventory ledger updated, sales counts synced.');
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function demoCustomers(): array
    {
        return [
            $this->customer('Sara', 'Ahmadi', 'sara.demo@example.com', '09120000001'),
            $this->customer('Reza', 'Karimi', 'reza.demo@example.com', '09120000002'),
            $this->customer('Nima', 'Rezaei', 'nima.demo@example.com', '09120000003'),
        ];
    }

    private function customer(string $name, string $lastName, string $email, string $phone): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'last_name' => $lastName,
                'phone' => $phone,
                'password' => Hash::make('Password@123'),
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles(['customer']);

        Address::firstOrCreate(
            ['user_id' => $user->id, 'title' => 'Home'],
            [
                'address' => "No. {$user->id}, Demo Street, {$name}'s place",
                'postal_code' => '1'.str_pad((string) $user->id, 9, '0', STR_PAD_LEFT),
                'is_default_shipping' => true,
            ],
        );

        return $user;
    }

    private function seedActiveCarts(User $user): void
    {
        // Authenticated user cart with two live items.
        $userCart = Cart::firstOrCreate(['user_id' => $user->id]);
        if ($userCart->items()->count() === 0) {
            CartItem::create(['cart_id' => $userCart->id, 'sku' => $this->variants[2]->sku, 'quantity' => 1]);
            CartItem::create(['cart_id' => $userCart->id, 'sku' => $this->variants[8]->sku, 'quantity' => 2]);
        }

        // Guest (session-based) cart.
        $guestCart = Cart::firstOrCreate(['session_id' => 'demo-guest-session-0001']);
        if ($guestCart->items()->count() === 0) {
            CartItem::create(['cart_id' => $guestCart->id, 'sku' => $this->variants[9]->sku, 'quantity' => 1]);
        }
    }

    /** @param array<int, array{0:int,1:int}> $lines */
    private function seedOrder(
        User $user,
        string $status,
        string $method,
        int $daysAgo,
        array $lines,
        string $shipmentMethod,
        ?string $shipmentState,
    ): void {
        $placedAt = Carbon::now()->subDays($daysAgo)->subHours(random_int(0, 12));

        $items = [];
        $total = 0;
        foreach ($lines as [$index, $qty]) {
            $variant = $this->variants[$index];
            $lineTotal = $variant->base_price * $qty;
            $total += $lineTotal;
            $title = $variant->product?->title ?? 'Product';
            // Mirrors CreateOrderAction: resolved once via the Catalog contract at
            // "checkout" time, then frozen — never re-read if the catalog changes later.
            $catalogVariant = $this->catalog->findVariantBySku($variant->sku);
            $items[] = [
                'sku' => $variant->sku,
                'product_title' => $title,
                'variant_attributes' => $variant->attributes,
                'product_snapshot' => [
                    'title' => $title,
                    'sku' => $variant->sku,
                    'image_url' => $catalogVariant?->imageUrl,
                    'attributes' => $variant->attributes,
                ],
                'quantity' => $qty,
                'price_per_unit' => $variant->base_price,
                'line_total' => $lineTotal,
            ];
        }

        $address = $user->addresses()->first();

        // Resolve the fulfillment selection through the Shipment contract exactly like
        // checkout does: it validates address ownership + local-delivery eligibility +
        // slot bookability, and hands back the immutable snapshot Order persists.
        $selection = $this->shipment->validateSelection(
            userId: $user->id,
            methodCode: $shipmentMethod,
            addressId: $address?->id,
            deliverySlotId: $shipmentMethod === 'local_delivery' ? $this->nextDeliverySlotId($user->id, (int) $address?->id) : null,
        );

        $snapshot = $selection->address ?? [
            'id' => $address?->id,
            'title' => $address?->title,
            'province_id' => $address?->province_id,
            'city_id' => $address?->city_id,
            'postal_code' => $address?->postal_code,
            'address' => $address?->address,
        ];

        // Mirrors CreateOrderAction: the buyer's identity is frozen onto the order at
        // "checkout" time via IdentityManagerInterface-equivalent fields — never re-read
        // from the User record later, even in this demo data.
        $customerSnapshot = [
            'name' => $user->name,
            'last_name' => $user->last_name,
            'phone' => $user->phone,
            'email' => $user->email,
        ];

        $note = "[demo] {$status} order";
        if ($shipmentState !== null) {
            $note .= " (shipment: {$shipmentState})";
        }

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => $total + $selection->shippingCost,
            'shipping_cost' => $selection->shippingCost,
            'tax_amount' => 0,
            'shipment_method_code' => $selection->methodCode,
            'shipping_address' => $snapshot,
            'shipment_snapshot' => $selection->toSnapshot(),
            'customer_snapshot' => $customerSnapshot,
            'notes' => $note,
        ]);

        foreach ($items as $item) {
            OrderItem::create(['order_id' => $order->id] + $item);
            // Every order reserves stock at creation, exactly like checkout.
            $this->inventory->reserveStock($item['sku'], $item['quantity'], $order->id);
        }

        // Local delivery consumes slot capacity from the moment the order is placed.
        $this->shipment->holdForPendingOrder(
            $order->id,
            $user->id,
            $selection,
            Carbon::now()->addMinutes((int) config('shipment.pending_order_ttl_minutes', 15)),
        );

        // Advance the reservation lifecycle to match the final status.
        if (in_array($status, ['paid', 'processing', 'shipped'], true)) {
            foreach ($items as $item) {
                $this->inventory->commitReservation($item['sku'], $item['quantity'], $order->id);
            }
            $ref = $method === 'in_person'
                ? 'CASH-'.strtoupper(Str::random(12))
                : 'REF-'.strtoupper(Str::random(12));
            $order->update(['status' => $status, 'transaction_ref' => $ref]);
        } elseif (in_array($status, ['cancelled', 'failed'], true)) {
            foreach ($items as $item) {
                $this->inventory->releaseReservation($item['sku'], $item['quantity'], $order->id);
            }
            // Terminal unpaid order — give the delivery session's capacity back too.
            $this->shipment->releasePendingOrder($order->id);
            $order->update(['status' => $status]);
        }
        // 'pending' keeps its reservation and its slot hold (no commit/release).

        $order->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();
    }

    /**
     * Round-robin over the bookable sessions so the demo local-delivery orders spread
     * across dates instead of exhausting the capacity of the first slot. Returns null
     * when no session is bookable, which surfaces as a clear validation error rather
     * than a silently address-less order.
     */
    private function nextDeliverySlotId(int $userId, int $addressId): ?int
    {
        if ($this->slotIds === null) {
            $this->slotIds = [];

            $grouped = $this->shipment->getAvailableDeliverySlots(
                $userId,
                $addressId,
                Carbon::now(),
                Carbon::now()->addDays((int) config('shipment.delivery.booking_horizon_days', 14)),
            );

            foreach ($grouped as $day) {
                foreach ($day['slots'] as $slot) {
                    if ($slot->available) {
                        $this->slotIds[] = $slot->id;
                    }
                }
            }
        }

        if ($this->slotIds === []) {
            return null;
        }

        return $this->slotIds[$this->slotCursor++ % count($this->slotIds)];
    }
}
