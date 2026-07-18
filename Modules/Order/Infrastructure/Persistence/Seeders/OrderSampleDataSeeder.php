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

/**
 * Seeds carts + orders + order items and drives the real Inventory reservation
 * lifecycle (reserve → commit / release) so the inventory ledger is an accurate
 * record of what the orders actually did. Payments are seeded separately by the
 * Payment module's PaymentSampleDataSeeder, keyed off the order status + the
 * transaction_ref prefix written here (CASH- = in-person, REF- = online).
 */
class OrderSampleDataSeeder extends Seeder
{
    private InventoryManagerInterface $inventory;

    private CatalogManagerInterface $catalog;

    /** @var array<int, ProductVariant> */
    private array $variants = [];

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

        [$sara, $reza, $nima] = $this->demoCustomers();

        // Active carts (not converted to orders) so cart data is visible in the API.
        $this->seedActiveCarts($sara);

        // Variant index map (ordered by id): 0-2 Galaxy S25 (128/256/512), 3-4 iPhone 16
        // (128/256), 5-6 MacBook Pro 14 (M4/M4 Pro), 7 AirPods Pro 2, 8-9 USB-C Hub (grey/silver).
        // Blueprint: [customer, status, method, daysAgo, lines[[variantIndex, qty]]].
        // Quantities stay small so no SKU is over-reserved against its 50-unit opening stock.
        $blueprints = [
            // Realized online sales (captured) — count toward best-sellers + physical stock drop.
            [$sara, 'paid',       'online',    9,  [[0, 1], [7, 2]]],      // Galaxy S25 128 + AirPods x2
            [$reza, 'shipped',    'online',    20, [[3, 1]]],             // iPhone 16 128
            [$nima, 'processing', 'online',    5,  [[5, 1], [7, 1]]],     // MacBook M4 + AirPods
            [$sara, 'shipped',    'online',    30, [[7, 3]]],             // AirPods x3 (best-seller)
            [$reza, 'paid',       'online',    2,  [[1, 1]]],             // Galaxy S25 256
            // Realized in-person (cash) sale — paid at counter, pending_cash payment.
            [$nima, 'paid',       'in_person', 12, [[9, 2], [8, 1]]],     // USB-C Hub silver x2 + grey
            // Open order — reservation still held, not yet paid.
            [$reza, 'pending',    'online',    0,  [[5, 1]]],             // MacBook M4 (reserved)
            // Abandoned / failed — reservation released, no physical stock change.
            [$sara, 'cancelled',  'online',    3,  [[3, 1]]],             // iPhone 16 128
            [$nima, 'failed',     'online',    1,  [[6, 1]]],             // MacBook M4 Pro
        ];

        foreach ($blueprints as [$user, $status, $method, $daysAgo, $lines]) {
            $this->seedOrder($user, $status, $method, $daysAgo, $lines);
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
    private function seedOrder(User $user, string $status, string $method, int $daysAgo, array $lines): void
    {
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
        $snapshot = [
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

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => $total,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => $snapshot,
            'customer_snapshot' => $customerSnapshot,
            'notes' => "[demo] {$status} order",
        ]);

        foreach ($items as $item) {
            OrderItem::create(['order_id' => $order->id] + $item);
            // Every order reserves stock at creation, exactly like checkout.
            $this->inventory->reserveStock($item['sku'], $item['quantity'], $order->id);
        }

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
            $order->update(['status' => $status]);
        }
        // 'pending' keeps its reservation held (no commit/release).

        $order->forceFill(['created_at' => $placedAt, 'updated_at' => $placedAt])->save();
    }
}
