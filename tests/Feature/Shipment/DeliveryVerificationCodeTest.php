<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Identity\Domain\Models\User;
use Modules\Shipment\Application\Actions\MarkLocalShipmentReadyAction;
use Modules\Shipment\Application\Actions\StartPreparingShipmentAction;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;

/**
 * The customer's handoff code, end to end: it is minted at dispatch, never
 * stored in the clear, valid only for the attempt it belongs to, and required
 * from everyone — courier and admin alike — to close the delivery.
 */
class DeliveryVerificationCodeTest extends ShipmentTestCase
{
    private FakeSmsProvider $sms;

    private ?int $slotId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();
        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    /** A paid local-delivery shipment; the acting user is an admin/operator. */
    private function paidLocalShipment(): Shipment
    {
        $customer = $this->actingAsOperator();
        $customer->forceFill(['phone' => '0912'.str_pad((string) $customer->id, 7, '0', STR_PAD_LEFT)])->save();

        $sku = 'CODE-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($customer->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', [
            'shipment_method_code' => 'local_delivery',
            'address_id' => $this->createAddress($customer->id),
            'delivery_slot_id' => $this->slotId ??= $this->createBookableSlot(capacity: 10)->id,
        ])->assertStatus(201)->json('id');

        $this->markOrderPaid($orderId);
        $this->sms->reset();

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    // ── dispatch requires an assignment ───────────────────────────────────────

    /** @test */
    public function a_local_delivery_cannot_be_dispatched_without_a_driver(): void
    {
        $shipment = $this->paidLocalShipment();
        $code = $shipment->public_code;

        $this->postJson("/api/v1/admin/shipments/{$code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-ready-for-dispatch")->assertOk();

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-out-for-delivery")
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_delivery_user_id');

        $shipment->refresh();
        $this->assertSame('ready_for_dispatch', $shipment->status);
        $this->assertNull($shipment->delivery_verification_code_hash);
    }

    /** @test */
    public function retrying_after_a_failed_attempt_also_requires_a_driver(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();
        $code = $shipment->public_code;

        $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);
        $this->postJson("/api/v1/admin/shipments/{$code}/mark-delivery-failed", ['failure_reason' => 'nobody_home'])
            ->assertOk();

        // Unassign by hand to model a driver leaving mid-round, then retry.
        DB::table('shipments')->where('id', $shipment->id)->update(['assigned_delivery_user_id' => null]);

        $this->postJson("/api/v1/admin/shipments/{$code}/mark-out-for-delivery")
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_delivery_user_id');
    }

    // ── the code itself ───────────────────────────────────────────────────────

    /** @test */
    public function dispatch_issues_a_code_and_persists_only_its_hash(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $handoff);

        $row = DB::table('shipments')->where('id', $shipment->id)->first();
        $this->assertNotNull($row->delivery_verification_code_hash);
        $this->assertNotNull($row->delivery_verification_issued_at);
        // The stored value is a hash, not the code — and not any encoding of it.
        $this->assertNotSame($handoff, $row->delivery_verification_code_hash);
        $this->assertStringNotContainsString($handoff, $row->delivery_verification_code_hash);
        $this->assertTrue(password_verify($handoff, $row->delivery_verification_code_hash));
    }

    /** @test */
    public function the_code_never_reaches_stored_notifications_or_api_responses(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        // Stored in-app notifications: the event happened, the secret did not travel.
        $notifications = DB::table('notifications')->where('type', 'shipment_out_for_delivery')->get();
        $this->assertNotEmpty($notifications);
        foreach ($notifications as $notification) {
            $this->assertStringNotContainsString($handoff, (string) $notification->data);
            $this->assertStringNotContainsString($handoff, (string) $notification->message);
        }

        // Shipment history rows.
        foreach (DB::table('shipment_status_histories')->where('shipment_id', $shipment->id)->get() as $history) {
            $this->assertStringNotContainsString($handoff, (string) $history->metadata);
        }

        // Admin, customer and driver read surfaces.
        $adminBody = $this->getJson("/api/v1/admin/shipments/{$shipment->public_code}")->assertOk()->getContent();
        $this->assertStringNotContainsString($handoff, $adminBody);

        $this->actingAs($driver, 'sanctum');
        $driverBody = $this->getJson("/api/v1/delivery/shipments/{$shipment->public_code}")->assertOk()->getContent();
        $this->assertStringNotContainsString($handoff, $driverBody);
    }

    /** @test */
    public function a_failed_attempt_invalidates_the_code_and_the_next_attempt_mints_a_new_one(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();
        $publicCode = $shipment->public_code;

        $first = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->postJson("/api/v1/admin/shipments/{$publicCode}/mark-delivery-failed", ['failure_reason' => 'nobody_home'])
            ->assertOk();

        $this->assertNull(DB::table('shipments')->where('id', $shipment->id)->value('delivery_verification_code_hash'));

        $second = $this->captureDeliveryCode(
            fn () => $this->postJson("/api/v1/admin/shipments/{$publicCode}/mark-out-for-delivery")->assertOk(),
        );

        $this->assertNotSame($first, $second);

        // The code the customer was given for the first attempt is dead.
        $this->actingAs($driver, 'sanctum');
        $this->postJson("/api/v1/delivery/shipments/{$publicCode}/mark-delivered", ['code' => $first])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->postJson("/api/v1/delivery/shipments/{$publicCode}/mark-delivered", ['code' => $second])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
    }

    /** @test */
    public function reassigning_mid_delivery_keeps_the_customers_code_but_moves_api_access(): void
    {
        $shipment = $this->paidLocalShipment();
        $first = $this->createDeliveryUser();
        $second = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $first, (int) $operator->id);

        $this->assignDriver($shipment->id, $second, (int) $operator->id);

        // The customer was not asked to memorise a new number.
        $this->assertTrue(password_verify(
            $handoff,
            (string) DB::table('shipments')->where('id', $shipment->id)->value('delivery_verification_code_hash'),
        ));

        // The old courier loses access immediately.
        $this->actingAs($first, 'sanctum');
        $this->getJson("/api/v1/delivery/shipments/{$shipment->public_code}")->assertStatus(404);

        $this->actingAs($second, 'sanctum');
        $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", ['code' => $handoff])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
    }

    // ── completion ────────────────────────────────────────────────────────────

    /** @test */
    public function the_assigned_driver_completes_with_the_right_code_and_the_order_completes(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->actingAs($driver, 'sanctum');
        $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", [
            'code' => $handoff,
            'receiver_name' => 'Sara Ahmadi',
            'note' => 'Delivered',
        ])->assertOk()->assertJsonPath('status', 'delivered');

        // Everything the existing transition machinery already owned still happens.
        $this->assertDatabaseHas('orders', ['id' => $shipment->order_id, 'status' => 'completed']);
        $this->assertDatabaseHas('delivery_slot_reservations', [
            'order_id' => $shipment->order_id, 'status' => 'completed',
        ]);
        $this->assertDatabaseHas('shipment_status_histories', [
            'shipment_id' => $shipment->id, 'to_status' => 'delivered',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $shipment->user_id, 'type' => 'shipment_delivered',
        ]);

        $shipment->refresh();
        $this->assertNotNull($shipment->delivered_at);
        $this->assertNotNull($shipment->delivery_verification_verified_at);
        // Consumed: the code cannot be replayed after the delivery closes.
        $this->assertNull($shipment->delivery_verification_code_hash);
    }

    /** @test */
    public function a_wrong_code_is_rejected_without_saying_why(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);
        $wrong = str_pad((string) ((((int) $handoff) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->actingAs($driver, 'sanctum');
        $response = $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", ['code' => $wrong])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // One generic message; nothing about expiry, staleness, or near-misses.
        $this->assertSame(['The delivery code is incorrect.'], $response->json('errors.code'));

        $this->assertDatabaseHas('shipments', ['id' => $shipment->id, 'status' => 'out_for_delivery']);
    }

    /** @test */
    public function an_admin_can_complete_but_still_needs_the_code(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        // No code at all — the admin route has no bypass.
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-delivered", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // A wrong code fares no better.
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-delivered", ['code' => '000000'])
            ->assertStatus(422);

        // The admin is not the assignee, and does not need to be.
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-delivered", ['code' => $handoff])
            ->assertOk()
            ->assertJsonPath('status', 'delivered');
    }

    /** @test */
    public function pickup_completion_still_needs_no_code(): void
    {
        // The code is a local-delivery mechanism; collecting at the counter is
        // already a face-to-face handover and keeps its existing flow.
        $customer = $this->actingAsOperator();
        $sku = 'PICKUP-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($customer->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', ['shipment_method_code' => 'in_person_pickup'])
            ->assertStatus(201)->json('id');
        $this->markOrderPaid($orderId);

        $shipment = Shipment::where('order_id', $orderId)->firstOrFail();

        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/start-preparing")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-ready-for-pickup")->assertOk();
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/confirm-pickup")
            ->assertOk()->assertJsonPath('status', 'picked_up');
    }

    // ── resend ────────────────────────────────────────────────────────────────

    /** @test */
    public function resending_mints_a_new_code_invalidates_the_old_one_and_sends_sms_only(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $first = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);
        $notificationsBefore = DB::table('notifications')->where('user_id', $shipment->user_id)->count();
        $this->sms->reset();

        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")->assertOk();

        // SMS only — no second "your order is on its way" in the customer's inbox.
        $this->assertSame(
            $notificationsBefore,
            DB::table('notifications')->where('user_id', $shipment->user_id)->count(),
        );

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        // A resend repeats the dispatch message with a fresh code — same template.
        $this->assertSame('shipment_out_for_delivery', $message->template);

        $second = $message->parameters['DeliveryCode'];
        $this->assertNotSame($first, $second);

        $this->actingAs($driver, 'sanctum');
        $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", ['code' => $first])
            ->assertStatus(422);
        $this->postJson("/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered", ['code' => $second])
            ->assertOk();
    }

    /** @test */
    public function resending_is_only_possible_while_the_delivery_is_under_way(): void
    {
        $shipment = $this->paidLocalShipment();

        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")
            ->assertStatus(422);
    }

    /** @test */
    public function resending_requires_the_resend_permission(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->actingAs($driver, 'sanctum');
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")->assertStatus(403);

        $shopper = User::factory()->create(['phone' => '09150000001']);
        $shopper->assignRole('customer');
        $this->actingAs($shopper, 'sanctum');
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")->assertStatus(403);

        app('auth')->forgetGuards();
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/resend-delivery-code")->assertStatus(401);
    }

    // ── brute force ───────────────────────────────────────────────────────────

    /** @test */
    public function repeated_wrong_codes_are_throttled(): void
    {
        config()->set('shipment.delivery.confirmation_max_attempts', 3);

        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->actingAs($driver, 'sanctum');
        $url = "/api/v1/delivery/shipments/{$shipment->public_code}/mark-delivered";

        for ($i = 0; $i < 3; $i++) {
            $this->postJson($url, ['code' => '000000'])->assertStatus(422);
        }

        // The budget is spent — even the *correct* code has to wait.
        $this->postJson($url, ['code' => $handoff])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    /** @test */
    public function the_throttle_is_scoped_to_one_shipment_at_a_time(): void
    {
        config()->set('shipment.delivery.confirmation_max_attempts', 2);

        $driver = $this->createDeliveryUser();
        $operator = $this->actingAsOperator();

        $first = $this->paidLocalShipment();
        $second = $this->paidLocalShipment();
        $operator = auth('sanctum')->user();

        $this->dispatchLocalDelivery($first->id, $driver, (int) $operator->id);
        $secondCode = $this->dispatchLocalDelivery($second->id, $driver, (int) $operator->id);

        $this->actingAs($driver, 'sanctum');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/v1/delivery/shipments/{$first->public_code}/mark-delivered", ['code' => '000000'])
                ->assertStatus(422);
        }
        $this->postJson("/api/v1/delivery/shipments/{$first->public_code}/mark-delivered", ['code' => '000000'])
            ->assertStatus(429);

        // Burning one delivery's budget must not strand the rest of the round.
        $this->postJson("/api/v1/delivery/shipments/{$second->public_code}/mark-delivered", ['code' => $secondCode])
            ->assertOk();
    }

    // ── the driver is not a fulfillment operator ──────────────────────────────

    /** @test */
    public function a_driver_cannot_dispatch_their_own_shipment(): void
    {
        $shipment = $this->paidLocalShipment();
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        app(StartPreparingShipmentAction::class)->handle($shipment->id, (int) $operator->id);
        app(MarkLocalShipmentReadyAction::class)->handle($shipment->id, (int) $operator->id);
        $this->assignDriver($shipment->id, $driver, (int) $operator->id);

        $this->actingAs($driver, 'sanctum');
        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-out-for-delivery")->assertStatus(403);
    }
}
