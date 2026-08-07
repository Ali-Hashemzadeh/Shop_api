<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Carbon\Carbon;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Payment\Domain\Models\Payment;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Shipment\ShipmentTestCase;

class CustomerOrderDetailTest extends ShipmentTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $itemOverrides
     */
    private function createOrder(User $user, array $overrides = [], array $itemOverrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 267000000,
            'shipping_cost' => 850000,
            'tax_amount' => 120000,
            'shipment_method_id' => null,
            'shipment_method_code' => 'post_standard',
            'shipping_address' => [
                'address_id' => 12,
                'province_id' => 8,
                'city_id' => 153,
                'address' => '123 Test Street',
            ],
            'shipment_snapshot' => [
                'method_code' => 'post_standard',
                'method_title' => 'Standard Post',
                'method_type' => 'postal',
                'shipping_cost' => 850000,
                'address' => ['address_id' => 12, 'address' => '123 Test Street'],
                'delivery_slot' => null,
                'pickup_location' => null,
            ],
            'customer_snapshot' => [
                'name' => 'Sara',
                'last_name' => 'Ahmadi',
                'phone' => '09123456789',
                'email' => 'sara@example.com',
            ],
            'transaction_ref' => 'REF-ORDER-DETAIL',
            'notes' => 'Leave at reception.',
        ], $overrides));

        OrderItem::create(array_merge([
            'order_id' => $order->id,
            'sku' => 'DETAIL-SKU-1',
            'product_title' => 'Historical Product',
            'variant_attributes' => ['color' => 'Black'],
            'product_snapshot' => [
                'title' => 'Historical Product',
                'sku' => 'DETAIL-SKU-1',
                'image_url' => '/storage/products/historical.webp',
                'primary_image_url' => '/storage/products/historical-primary.webp',
                'attributes' => ['color' => 'Black', 'storage' => '256GB'],
            ],
            'quantity' => 2,
            'max_quantity_per_order_snapshot' => 4,
            'price_per_unit' => 133015000,
            'compare_at_price' => 145000000,
            'line_total' => 266030000,
        ], $itemOverrides));

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPayment(Order $order, string $reference, array $overrides = []): Payment
    {
        return Payment::createWithPublicCode(array_merge([
            'order_id' => $order->id,
            'method_type' => 'online',
            'gateway' => 'zarinpal',
            'transaction_reference' => $reference,
            'amount' => $order->total_amount,
            'status' => 'initiated',
            'gateway_response' => ['provider_secret' => 'must-never-leak'],
        ], $overrides));
    }

    #[Test]
    public function customer_can_retrieve_their_complete_order_by_normalized_public_code(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createOrder($user);

        $response = $this->getJson('/api/v1/orders/'.strtolower((string) $order->public_code))
            ->assertOk()
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('public_code', $order->public_code)
            ->assertJsonPath('shipment_snapshot.method_code', 'post_standard')
            ->assertJsonPath('customer_snapshot.last_name', 'Ahmadi')
            ->assertJsonPath('items.0.product_snapshot.title', 'Historical Product')
            ->assertJsonPath('items.0.product_snapshot.image_url', '/storage/products/historical.webp')
            ->assertJsonPath('items.0.product_snapshot.primary_image_url', '/storage/products/historical-primary.webp')
            ->assertJsonPath('items.0.product_snapshot.attributes.storage', '256GB')
            ->assertJsonPath('items.0.max_quantity_per_order_snapshot', 4)
            ->assertJsonPath('items.0.price_per_unit', 133015000)
            ->assertJsonPath('items.0.compare_at_price', 145000000)
            ->assertJsonPath('payments', [])
            ->assertJsonPath('shipment', null);

        $this->assertMatchesRegularExpression(
            '/^'.preg_quote(PublicCodeGenerator::prefix(PublicCodeEntity::Order), '/').'[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/',
            (string) $order->public_code,
        );

        $this->assertSame([
            'id',
            'public_code',
            'status',
            'total_amount',
            'shipping_cost',
            'tax_amount',
            'shipment_method_id',
            'shipment_method_code',
            'shipping_address',
            'shipment_snapshot',
            'customer_snapshot',
            'transaction_ref',
            'notes',
            'created_at',
            'items',
            'payments',
            'shipment',
        ], array_keys($response->json()));

        $this->assertSame([
            'id',
            'sku',
            'product_title',
            'variant_attributes',
            'product_snapshot',
            'quantity',
            'max_quantity_per_order_snapshot',
            'price_per_unit',
            'compare_at_price',
            'line_total',
        ], array_keys($response->json('items.0')));
    }

    #[Test]
    public function customer_detail_serializes_null_compare_at_for_a_legacy_product_snapshot(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createOrder($user, [], [
            'product_snapshot' => [
                'title' => 'Legacy Snapshot',
                'sku' => 'DETAIL-SKU-1',
                'image_url' => '/storage/products/legacy-variant.webp',
                'attributes' => [],
            ],
            'compare_at_price' => null,
        ]);

        $response = $this->getJson('/api/v1/orders/'.$order->public_code)
            ->assertOk()
            ->assertJsonPath('items.0.compare_at_price', null)
            ->assertJsonPath('items.0.product_snapshot.image_url', '/storage/products/legacy-variant.webp');

        $this->assertArrayNotHasKey('primary_image_url', $response->json('items.0.product_snapshot'));
    }

    #[Test]
    public function every_payment_attempt_is_returned_newest_first_with_only_customer_safe_fields(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createOrder($user);
        $old = $this->createPayment($order, 'AUTH-OLD', ['status' => 'failed']);
        $firstAtNewestTime = $this->createPayment($order, 'AUTH-CAPTURED', ['status' => 'captured']);
        $secondAtNewestTime = $this->createPayment($order, 'AUTH-NEWEST', ['status' => 'initiated']);

        $old->forceFill(['created_at' => Carbon::parse('2026-08-05 10:00:00')])->save();
        $firstAtNewestTime->forceFill(['created_at' => Carbon::parse('2026-08-07 10:00:00')])->save();
        $secondAtNewestTime->forceFill(['created_at' => Carbon::parse('2026-08-07 10:00:00')])->save();

        $response = $this->getJson('/api/v1/orders/'.$order->public_code)
            ->assertOk()
            ->assertJsonCount(3, 'payments')
            ->assertJsonPath('payments.0.id', $secondAtNewestTime->id)
            ->assertJsonPath('payments.1.id', $firstAtNewestTime->id)
            ->assertJsonPath('payments.2.id', $old->id);

        foreach ($response->json('payments') as $payment) {
            $this->assertSame([
                'id',
                'public_code',
                'order_id',
                'method_type',
                'gateway',
                'status',
                'amount',
                'transaction_reference',
                'created_at',
            ], array_keys($payment));
            $this->assertArrayNotHasKey('gateway_response', $payment);
            $this->assertArrayNotHasKey('provider_secret', $payment);
        }
    }

    #[Test]
    public function paid_order_includes_the_complete_live_shipment_and_history(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->createOrder($user, ['status' => 'shipped']);
        $timestamp = Carbon::parse('2026-08-07 12:00:00');

        $shipment = Shipment::createWithPublicCode([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'method_code' => 'local_delivery',
            'method_title' => 'Local Delivery',
            'method_type' => 'local_delivery',
            'shipping_cost' => 850000,
            'status' => 'out_for_delivery',
            'address_snapshot' => ['address_id' => 12, 'address' => '123 Test Street'],
            'delivery_slot_snapshot' => ['slot_id' => 44, 'date' => '2026-08-07', 'starts_at' => '12:00:00', 'ends_at' => '13:30:00'],
            'pickup_location_snapshot' => null,
            'carrier_name' => 'Store Fleet',
            'tracking_number' => 'LOCAL-TRACK-1',
            'receiver_name' => null,
            'failure_reason' => null,
            'note' => 'Driver is en route.',
            'handed_to_post_at' => $timestamp->copy()->subHours(3),
            'out_for_delivery_at' => $timestamp,
            'delivered_at' => null,
            'ready_for_pickup_at' => null,
            'picked_up_at' => null,
        ]);

        ShipmentStatusHistory::create([
            'shipment_id' => $shipment->id,
            'from_status' => null,
            'to_status' => 'pending',
            'reason' => 'payment_captured',
            'note' => null,
            'metadata' => [],
            'created_at' => $timestamp->copy()->subDay(),
        ]);
        ShipmentStatusHistory::create([
            'shipment_id' => $shipment->id,
            'from_status' => 'ready_for_dispatch',
            'to_status' => 'out_for_delivery',
            'reason' => null,
            'note' => 'Driver departed.',
            'metadata' => ['vehicle' => 'VAN-7'],
            'created_at' => $timestamp,
        ]);

        $response = $this->getJson('/api/v1/orders/'.$order->public_code)
            ->assertOk()
            ->assertJsonPath('shipment_snapshot.method_code', 'post_standard')
            ->assertJsonPath('shipment.id', $shipment->public_code)
            ->assertJsonPath('shipment.order_id', $order->id)
            ->assertJsonPath('shipment.method_title', 'Local Delivery')
            ->assertJsonPath('shipment.address.address_id', 12)
            ->assertJsonPath('shipment.delivery_slot.slot_id', 44)
            ->assertJsonPath('shipment.history.1.to_status', 'out_for_delivery')
            ->assertJsonPath('shipment.history.1.metadata.vehicle', 'VAN-7')
            ->assertJsonCount(2, 'shipment.history');

        $this->assertSame([
            'id',
            'order_id',
            'method_code',
            'method_title',
            'method_type',
            'shipping_cost',
            'status',
            'status_label',
            'address',
            'delivery_slot',
            'pickup_location',
            'carrier_name',
            'tracking_number',
            'receiver_name',
            'failure_reason',
            'note',
            'handed_to_post_at',
            'out_for_delivery_at',
            'delivered_at',
            'ready_for_pickup_at',
            'picked_up_at',
            'created_at',
            'history',
        ], array_keys($response->json('shipment')));

        $this->assertSame([
            'from_status',
            'to_status',
            'reason',
            'note',
            'metadata',
            'created_at',
        ], array_keys($response->json('shipment.history.0')));
        $this->assertNotSame($shipment->id, $response->json('shipment.id'));
    }

    #[Test]
    public function foreign_owned_and_nonexistent_codes_have_identical_not_found_responses(): void
    {
        config()->set('app.debug', false);

        $viewer = $this->actingAsCustomer();
        $otherCustomer = User::factory()->create();
        $foreignOrder = $this->createOrder($otherCustomer);

        do {
            $missingCode = PublicCodeGenerator::generate(PublicCodeEntity::Order);
        } while ($missingCode === $foreignOrder->public_code);

        $foreign = $this->getJson('/api/v1/orders/'.$foreignOrder->public_code)->assertNotFound();
        $missing = $this->getJson('/api/v1/orders/'.$missingCode)->assertNotFound();

        $this->assertSame($foreign->getContent(), $missing->getContent());
        $this->assertDatabaseHas('users', ['id' => $viewer->id]);
    }

    #[Test]
    public function guest_invalid_partial_and_numeric_detail_requests_do_not_resolve(): void
    {
        $owner = User::factory()->create();
        $order = $this->createOrder($owner);

        $this->getJson('/api/v1/orders/'.$order->public_code)->assertUnauthorized();

        $this->actingAsCustomer($owner);

        $this->getJson('/api/v1/orders/'.substr((string) $order->public_code, 0, -1))->assertNotFound();
        $this->getJson('/api/v1/orders/'.$order->id)->assertNotFound();
        $this->getJson('/api/v1/orders/not-an-order-code')->assertNotFound();
    }

    #[Test]
    public function existing_customer_list_cancel_and_admin_detail_shapes_do_not_gain_aggregate_fields(): void
    {
        $customer = $this->actingAsCustomer();
        $order = $this->createOrder($customer, ['transaction_ref' => null]);
        $this->createPayment($order, 'EXISTING-ENDPOINT-ATTEMPT');
        InventoryStock::create([
            'sku' => 'DETAIL-SKU-1',
            'quantity' => 10,
            'reserved_quantity' => 2,
        ]);

        $list = $this->getJson('/api/v1/orders')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('payments', $list);
        $this->assertArrayNotHasKey('shipment', $list);

        $cancelled = $this->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->json();
        $this->assertArrayNotHasKey('payments', $cancelled);
        $this->assertArrayNotHasKey('shipment', $cancelled);

        $this->actingAsAdmin();
        $admin = $this->getJson('/api/v1/admin/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->json('data');
        $this->assertArrayNotHasKey('payments', $admin);
        $this->assertSame([
            'id',
            'public_code',
            'status',
            'total_amount',
            'shipping_cost',
            'tax_amount',
            'transaction_ref',
            'notes',
            'created_at',
            'customer',
            'shipping_address',
            'shipment_snapshot',
            'shipment',
            'items',
        ], array_keys($admin));
    }
}
