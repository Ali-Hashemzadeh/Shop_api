<?php

declare(strict_types=1);

namespace Tests\Feature\Shipment;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Identity\Domain\Models\User;
use Modules\Notification\Application\Listeners\SendShipmentAssignedToDeliveryNotifications;
use Modules\Shipment\Domain\Models\Shipment;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;

/**
 * Putting a courier in charge of a delivery: which shipments can be assigned,
 * who can receive one, and what the audit trail and the courier's phone see.
 */
class DeliveryAssignmentTest extends ShipmentTestCase
{
    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();
        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    private function paidShipment(string $methodCode): Shipment
    {
        $user = $this->actingAsOperator();
        // Unique per call: a single test may buy more than one shipment, and each
        // purchase acts as a fresh operator.
        $user->forceFill(['phone' => '0912'.str_pad((string) $user->id, 7, '0', STR_PAD_LEFT)])->save();

        $payload = [
            'shipment_method_code' => $methodCode,
            'address_id' => $this->createAddress($user->id),
        ];

        if ($methodCode === 'local_delivery') {
            $payload['delivery_slot_id'] = $this->createBookableSlot()->id;
        }

        $sku = 'ASSIGN-'.uniqid();
        $this->createVariantWithStock($sku, 10000, 5);
        $this->addToCart($user->id, $sku, 1);

        $orderId = $this->postJson('/api/v1/orders', $payload)->assertStatus(201)->json('id');
        $this->markOrderPaid($orderId);
        $this->sms->reset();

        return Shipment::where('order_id', $orderId)->firstOrFail();
    }

    private function assign(Shipment $shipment, int $deliveryUserId): TestResponse
    {
        return $this->postJson(
            "/api/v1/admin/shipments/{$shipment->public_code}/assign-delivery",
            ['delivery_user_id' => $deliveryUserId],
        );
    }

    /** @test */
    public function a_local_delivery_can_be_assigned_and_reassigned(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $first = $this->createDeliveryUser();
        $second = $this->createDeliveryUser();

        $this->assign($shipment, $first->id)->assertOk();
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'assigned_delivery_user_id' => $first->id,
        ]);

        $this->assign($shipment, $second->id)->assertOk();
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'assigned_delivery_user_id' => $second->id,
        ]);

        // The first assignment is closed, not deleted; exactly one row stays open.
        $this->assertDatabaseHas('shipment_delivery_assignments', [
            'shipment_id' => $shipment->id,
            'delivery_user_id' => $first->id,
        ]);
        $this->assertSame(2, DB::table('shipment_delivery_assignments')->where('shipment_id', $shipment->id)->count());
        $this->assertSame(1, DB::table('shipment_delivery_assignments')
            ->where('shipment_id', $shipment->id)->whereNull('unassigned_at')->count());
    }

    /** @test */
    public function the_admin_shipment_resource_reports_assignment_state_but_never_the_code(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $url = "/api/v1/admin/shipments/{$shipment->public_code}";

        $this->getJson($url)->assertOk()
            ->assertJsonPath('assigned_delivery_user_id', null)
            ->assertJsonPath('delivery_assigned_at', null)
            ->assertJsonPath('has_active_delivery_code', false);

        $this->assign($shipment, $driver->id)->assertOk()
            ->assertJsonPath('assigned_delivery_user_id', $driver->id)
            ->assertJsonPath('has_active_delivery_code', false);

        $handoff = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('assigned_delivery_user_id', $driver->id)
            ->assertJsonPath('has_active_delivery_code', true);

        $this->assertNotNull($response->json('delivery_assigned_at'));
        // The flag says a code is outstanding; it never says which.
        $this->assertStringNotContainsString($handoff, $response->getContent());
    }

    /** @test */
    public function assignment_records_who_assigned_it(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $admin = auth('sanctum')->user();
        $driver = $this->createDeliveryUser();

        $this->assign($shipment, $driver->id)->assertOk();

        $this->assertDatabaseHas('shipment_delivery_assignments', [
            'shipment_id' => $shipment->id,
            'delivery_user_id' => $driver->id,
            'assigned_by_user_id' => $admin->id,
            'unassigned_at' => null,
        ]);
    }

    /** @test */
    public function postal_and_pickup_shipments_cannot_be_assigned(): void
    {
        $driver = $this->createDeliveryUser();

        foreach (['post_standard', 'in_person_pickup'] as $method) {
            $shipment = $this->paidShipment($method);

            $this->assign($shipment, $driver->id)
                ->assertStatus(422)
                ->assertJsonValidationErrors('delivery_user_id');

            $this->assertDatabaseHas('shipments', [
                'id' => $shipment->id,
                'assigned_delivery_user_id' => null,
            ]);
        }
    }

    /** @test */
    public function a_user_without_the_delivery_role_cannot_be_assigned(): void
    {
        $shipment = $this->paidShipment('local_delivery');

        $shopper = User::factory()->create(['phone' => '09120000003']);
        $shopper->assignRole('customer');

        $this->assign($shipment, $shopper->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_user_id');
    }

    /** @test */
    public function a_delivery_worker_without_a_phone_cannot_be_assigned(): void
    {
        $shipment = $this->paidShipment('local_delivery');

        $driver = $this->createDeliveryUser();
        $driver->forceFill(['phone' => null])->save();

        $this->assign($shipment, $driver->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_user_id');
    }

    /** @test */
    public function reassigning_the_same_driver_is_idempotent_and_silent(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        $this->assign($shipment, $driver->id)->assertOk();
        $notificationsAfterFirst = DB::table('notifications')->where('user_id', $driver->id)->count();
        $smsAfterFirst = count($this->sms->sent());

        $this->assign($shipment, $driver->id)->assertOk();

        $this->assertSame(1, DB::table('shipment_delivery_assignments')->where('shipment_id', $shipment->id)->count());
        $this->assertSame($notificationsAfterFirst, DB::table('notifications')->where('user_id', $driver->id)->count());
        $this->assertSame($smsAfterFirst, count($this->sms->sent()));
    }

    /** @test */
    public function the_assigned_driver_gets_an_in_app_notification_and_an_sms(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser('09120000004');

        $this->assign($shipment, $driver->id)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $driver->id,
            'type' => 'shipment_assigned_delivery',
        ]);

        $message = $this->sms->lastMessage();
        $this->assertNotNull($message);
        $this->assertSame('shipment_assigned_delivery', $message->template);
        $this->assertSame('09120000004', $message->receiver);
        $this->assertSame($shipment->public_code, $message->parameters['ShipmentId']);
        // The slot the delivery is booked into travels with the assignment.
        $this->assertArrayHasKey('DeliveryDate', $message->parameters);
    }

    /** @test */
    public function the_assignment_sms_never_carries_the_address_or_a_handoff_code(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        $this->assign($shipment, $driver->id)->assertOk();

        $parameters = $this->sms->lastMessage()->parameters;

        $this->assertArrayNotHasKey('DeliveryCode', $parameters);
        foreach ($parameters as $value) {
            $this->assertStringNotContainsString('123 Test Street', (string) $value);
        }
    }

    /** @test */
    public function the_assignment_listener_runs_only_after_commit(): void
    {
        // The mechanism the whole notification module relies on: a rolled-back
        // assignment must never page a driver.
        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            app(SendShipmentAssignedToDeliveryNotifications::class),
        );
    }

    /** @test */
    public function a_finished_shipment_cannot_be_assigned(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();
        $operator = auth('sanctum')->user();

        $code = $this->dispatchLocalDelivery($shipment->id, $driver, (int) $operator->id);

        $this->postJson("/api/v1/admin/shipments/{$shipment->public_code}/mark-delivered", ['code' => $code])
            ->assertOk();

        $other = $this->createDeliveryUser();
        $this->assign($shipment->fresh(), $other->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('delivery_user_id');
    }

    /** @test */
    public function assigning_requires_the_assign_permission(): void
    {
        $shipment = $this->paidShipment('local_delivery');
        $driver = $this->createDeliveryUser();

        // A courier cannot hand deliveries to themselves.
        $this->actingAs($driver, 'sanctum');
        $this->assign($shipment, $driver->id)->assertStatus(403);

        app('auth')->forgetGuards();
        $this->assign($shipment, $driver->id)->assertStatus(401);
    }
}
