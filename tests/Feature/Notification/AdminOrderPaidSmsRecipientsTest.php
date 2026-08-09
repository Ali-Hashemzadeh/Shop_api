<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Models\User;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Notification\Domain\Models\Notification;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Order\Domain\Models\Order;
use Modules\Order\Domain\Models\OrderItem;
use Modules\Sms\Domain\DTOs\SmsMessageDTO;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Tests\TestCase;

/**
 * Who receives the paid-order admin SMS, and who only gets the in-app copy.
 *
 * The in-app notification is universal and not configurable; the SMS is opt-in
 * per admin. Both facts are asserted together in most cases here, because the
 * failure that matters is one of them silently following the other.
 */
class AdminOrderPaidSmsRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/notifications/admin-order-paid-sms-recipients';

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedInventoryPermissions();
        $this->seedOrderPermissions();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();

        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createAdmin(?string $phone = '09120000000'): User
    {
        $user = User::factory()->create();
        // Derived from the id so several admins in one test never collide on the
        // unique phone column.
        $user->forceFill([
            'phone' => $phone === null ? null : '0912'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT),
        ])->save();
        $user->assignRole('admin');

        return $user->fresh();
    }

    private function payAnOrder(): Order
    {
        $customer = User::factory()->create(['phone' => '09351112233']);

        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'total_amount' => 250000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'Test Street'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku' => 'ADMIN-SMS-SKU',
            'quantity' => 1,
            'price_per_unit' => 250000,
            'line_total' => 250000,
            'product_title' => 'Test product',
        ]);

        $inventory = app(InventoryManagerInterface::class);
        $inventory->adjustStock('ADMIN-SMS-SKU', 5, 'restock');
        $inventory->reserveStock('ADMIN-SMS-SKU', 1, $order->id);

        app(OrderManagerInterface::class)->markAsPaid($order->id, 'REF-'.$order->id);

        return $order->fresh();
    }

    /** @return list<SmsMessageDTO> */
    private function adminMessages(): array
    {
        return array_values(array_filter(
            $this->sms->sent(),
            fn (SmsMessageDTO $m) => $m->template === 'admin_order_paid',
        ));
    }

    // ── The fan-out ───────────────────────────────────────────────────────────

    /** @test */
    public function every_admin_receives_the_in_app_notification_but_only_selected_admins_get_the_sms(): void
    {
        $selectedA = $this->createAdmin();
        $unselected = $this->createAdmin();
        $selectedC = $this->createAdmin();

        $this->actingAs($selectedA, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$selectedA->id, $selectedC->id]])->assertOk();
        $this->sms->reset();

        $order = $this->payAnOrder();

        foreach ([$selectedA, $unselected, $selectedC] as $admin) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $admin->id,
                'type' => 'admin_order_paid',
                'message' => "سفارش شماره {$order->public_code} پرداخت شد.",
            ]);
        }

        $receivers = array_map(fn (SmsMessageDTO $m) => $m->receiver, $this->adminMessages());
        sort($receivers);
        $expected = [$selectedA->phone, $selectedC->phone];
        sort($expected);

        $this->assertSame($expected, $receivers);
        $this->assertNotContains($unselected->phone, $receivers);
    }

    /** @test */
    public function the_admin_sms_uses_its_own_template_and_the_order_public_code(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$admin->id]])->assertOk();
        $this->sms->reset();

        $order = $this->payAnOrder();

        $messages = $this->adminMessages();
        $this->assertCount(1, $messages);
        // Not the customer's payment_success template, and not a numeric id.
        $this->assertMatchesRegularExpression('/^bdo-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $order->public_code);
        $this->assertSame(['OrderId' => $order->public_code], $messages[0]->parameters);

        // The customer's own receipt is untouched by any of this.
        $customerMessages = array_values(array_filter(
            $this->sms->sent(),
            fn (SmsMessageDTO $m) => $m->template === 'payment_success',
        ));
        $this->assertCount(1, $customerMessages);
        $this->assertSame(['OrderId' => $order->public_code], $customerMessages[0]->parameters);
    }

    /** @test */
    public function with_nobody_selected_the_paid_order_produces_no_admin_sms(): void
    {
        $this->createAdmin();

        $this->payAnOrder();

        $this->assertSame([], $this->adminMessages());
        $this->assertDatabaseHas('notifications', ['type' => 'admin_order_paid']);
    }

    /** @test */
    public function a_selected_admin_without_a_phone_is_skipped_without_breaking_the_payment(): void
    {
        $withPhone = $this->createAdmin();
        $withoutPhone = $this->createAdmin(phone: null);

        $this->actingAs($withPhone, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$withPhone->id, $withoutPhone->id]])->assertOk();
        $this->sms->reset();

        $order = $this->payAnOrder();

        // The order is paid, the phone-less admin still has their in-app copy, and
        // the miss is recorded as a skip rather than a failure.
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('notifications', ['user_id' => $withoutPhone->id, 'type' => 'admin_order_paid']);

        $notification = Notification::where('user_id', $withoutPhone->id)
            ->where('type', 'admin_order_paid')
            ->firstOrFail();

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'channel' => 'sms',
            'status' => 'skipped',
        ]);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'failed']);
        $this->assertCount(1, $this->adminMessages());
    }

    /** @test */
    public function a_missing_admin_template_does_not_break_the_payment(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$admin->id]])->assertOk();

        // Real provider, credentials present, this one template id absent → skip.
        config()->set('sms.default', 'smsir');
        config()->set('sms.providers.smsir.api_key', 'test-key');
        config()->set('sms.providers.smsir.templates.admin_order_paid', null);

        $order = $this->payAnOrder();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'admin_order_paid']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'sms', 'status' => 'skipped']);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'failed']);
    }

    // ── The settings endpoints ────────────────────────────────────────────────

    /** @test */
    public function the_listing_returns_every_admin_with_their_selection_state(): void
    {
        $selected = $this->createAdmin();
        $unselected = $this->createAdmin();
        // A customer is not an admin and must not appear in the picker.
        $this->actingAsCustomer();

        $this->actingAs($selected, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$selected->id]])->assertOk();

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $rows = collect($response->json('data'))->keyBy('user_id');
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[$selected->id]['enabled']);
        $this->assertFalse($rows[$unselected->id]['enabled']);
        $this->assertSame($selected->phone, $rows[$selected->id]['phone']);
        $this->assertArrayHasKey('name', $rows[$selected->id]);
    }

    /** @test */
    public function updating_the_recipient_list_is_idempotent(): void
    {
        $first = $this->createAdmin();
        $second = $this->createAdmin();

        $this->actingAs($first, 'sanctum');

        $this->putJson(self::ENDPOINT, ['user_ids' => [$first->id, $second->id]])->assertOk();
        $this->putJson(self::ENDPOINT, ['user_ids' => [$first->id, $second->id]])->assertOk();

        $this->assertDatabaseCount('notification_recipient_preferences', 2);
        $this->assertDatabaseHas('notification_recipient_preferences', [
            'user_id' => $first->id,
            'notification_type' => 'admin_order_paid',
            'channel' => 'sms',
            'enabled' => true,
        ]);
    }

    /** @test */
    public function removing_an_admin_from_the_list_stops_their_sms(): void
    {
        $kept = $this->createAdmin();
        $dropped = $this->createAdmin();

        $this->actingAs($kept, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$kept->id, $dropped->id]])->assertOk();
        $this->putJson(self::ENDPOINT, ['user_ids' => [$kept->id]])->assertOk();

        // The row survives as the record of a decision, flipped to disabled.
        $this->assertDatabaseHas('notification_recipient_preferences', [
            'user_id' => $dropped->id,
            'enabled' => false,
        ]);

        $this->sms->reset();
        $this->payAnOrder();

        $receivers = array_map(fn (SmsMessageDTO $m) => $m->receiver, $this->adminMessages());
        $this->assertSame([$kept->phone], $receivers);
    }

    /** @test */
    public function an_empty_list_clears_every_recipient(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum');
        $this->putJson(self::ENDPOINT, ['user_ids' => [$admin->id]])->assertOk();
        $this->putJson(self::ENDPOINT, ['user_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.0.enabled', false);

        $this->sms->reset();
        $this->payAnOrder();

        $this->assertSame([], $this->adminMessages());
    }

    /** @test */
    public function a_non_admin_id_is_rejected_and_changes_nothing(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $this->actingAs($admin, 'sanctum');

        $this->putJson(self::ENDPOINT, ['user_ids' => [$admin->id, $customer->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids.1');

        // Rejected before any write — not even the valid half landed.
        $this->assertDatabaseCount('notification_recipient_preferences', 0);
    }

    /** @test */
    public function an_unknown_user_id_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum');

        $this->putJson(self::ENDPOINT, ['user_ids' => [999999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids.0');
    }

    /** @test */
    public function a_missing_user_ids_key_is_a_validation_error(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'sanctum');

        $this->putJson(self::ENDPOINT, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_ids');
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    /** @test */
    public function a_guest_is_unauthenticated_on_both_endpoints(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
        $this->putJson(self::ENDPOINT, ['user_ids' => []])->assertStatus(401);
    }

    /** @test */
    public function a_customer_is_forbidden_on_both_endpoints(): void
    {
        $this->actingAsCustomer();

        $this->getJson(self::ENDPOINT)->assertStatus(403);
        // 403 before validation: an unauthorized caller never learns whether their
        // body was well-formed.
        $this->putJson(self::ENDPOINT, ['user_ids' => 'not-an-array'])->assertStatus(403);
    }
}
