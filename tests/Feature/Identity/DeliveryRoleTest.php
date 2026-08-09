<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Identity\Domain\Contracts\OtpSenderInterface;
use Modules\Identity\Domain\Models\User;
use Tests\TestCase;

/**
 * The delivery role and the admin surface that hands it out.
 *
 * The invariant under test throughout: a delivery worker is a shopper who *also*
 * delivers. Granting or creating one must never cost the account a capability it
 * would otherwise have had.
 */
class DeliveryRoleTest extends TestCase
{
    use RefreshDatabase;

    private object $otpSender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedShipmentPermissions();

        $this->otpSender = new class implements OtpSenderInterface
        {
            /** @var array<int, array{phone: string, code: string}> */
            public array $sent = [];

            public function send(string $phone, string $code): void
            {
                $this->sent[] = ['phone' => $phone, 'code' => $code];
            }
        };

        $this->app->instance(OtpSenderInterface::class, $this->otpSender);
    }

    /** @test */
    public function the_delivery_role_exists_and_carries_only_assigned_shipment_permissions(): void
    {
        $user = User::factory()->create(['phone' => '09120000009']);
        $user->assignRole('customer');
        $user->assignRole('delivery');

        $this->assertTrue($user->hasRole('delivery'));

        // What a courier may do.
        $this->assertTrue($user->can('shipment.delivery.view-assigned'));
        $this->assertTrue($user->can('shipment.delivery.complete-assigned'));

        // What a courier may not do — running fulfillment stays with the store.
        foreach ([
            'shipment.view-admin',
            'shipment.delivery.assign',
            'shipment.delivery.dispatch',
            'shipment.delivery.mark-ready',
            'shipment.delivery.complete',
            'shipment.delivery.fail',
            'shipment.delivery.reschedule',
            'shipment.delivery.resend-code',
            'shipment.start-preparing',
            'shipment.slot.manage',
        ] as $forbidden) {
            $this->assertFalse($user->can($forbidden), "delivery must not hold {$forbidden}");
        }

        // …and still shops like anyone else.
        $this->assertTrue($user->can('shipment.view-own'));
        $this->assertTrue($user->can('address.create-own'));
    }

    /** @test */
    public function an_admin_creates_a_customer(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Ali',
            'last_name' => 'Ahmadi',
            'phone' => '09123456789',
            'role' => 'customer',
        ])->assertStatus(201)->assertJsonPath('data.roles', ['customer']);

        $user = User::where('phone', '09123456789')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertFalse($user->hasRole('delivery'));
        // No credential is minted: the phone is the credential.
        $this->assertNull($user->password);
    }

    /** @test */
    public function an_admin_creates_a_delivery_worker_who_is_also_a_customer(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Sara',
            'last_name' => 'Ahmadi',
            'phone' => '09123456780',
            'email' => 'sara@example.com',
            'role' => 'delivery',
        ])->assertStatus(201);

        $this->assertEqualsCanonicalizing(['customer', 'delivery'], $response->json('data.roles'));

        $user = User::where('phone', '09123456780')->firstOrFail();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertTrue($user->hasRole('delivery'));
        $this->assertTrue(app(IdentityManagerInterface::class)->isDeliveryUser($user->id));
    }

    /** @test */
    public function an_admin_created_account_can_still_sign_in_through_the_existing_otp_flow(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Reza',
            'phone' => '09123456781',
            'role' => 'delivery',
        ])->assertStatus(201);

        // Drop the admin token — the courier signs in as themselves.
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/otp/request', ['phone' => '09123456781'])
            ->assertOk()
            ->assertJsonPath('is_new_user', false);

        $code = $this->otpSender->sent[array_key_last($this->otpSender->sent)]['code'];

        $this->postJson('/api/v1/otp/verify', [
            'phone' => '09123456781',
            'code' => $code,
            'device_name' => 'courier-phone',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    /** @test */
    public function admin_is_not_a_creatable_role(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Escalation',
            'phone' => '09123456782',
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    /** @test */
    public function granting_the_delivery_role_keeps_every_existing_role(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create(['phone' => '09123456783']);
        $user->assignRole('customer');

        $this->postJson("/api/v1/admin/users/{$user->id}/delivery-role")->assertOk();

        $user->refresh();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertTrue($user->hasRole('delivery'));
    }

    /** @test */
    public function granting_the_delivery_role_twice_changes_nothing(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create(['phone' => '09123456784']);
        $user->assignRole('customer');

        $this->postJson("/api/v1/admin/users/{$user->id}/delivery-role")->assertOk();
        $this->postJson("/api/v1/admin/users/{$user->id}/delivery-role")->assertOk();

        $this->assertSame(1, $user->fresh()->roles()->where('name', 'delivery')->count());
    }

    /** @test */
    public function a_user_without_a_phone_cannot_become_a_delivery_worker(): void
    {
        $this->actingAsAdmin();

        $user = User::factory()->create(['phone' => null]);
        $user->assignRole('customer');

        $this->postJson("/api/v1/admin/users/{$user->id}/delivery-role")
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertFalse($user->fresh()->hasRole('delivery'));
    }

    /** @test */
    public function the_user_list_filters_by_role(): void
    {
        $this->actingAsAdmin();

        $courier = User::factory()->create(['phone' => '09123456785']);
        $courier->assignRole('customer');
        $courier->assignRole('delivery');

        $shopper = User::factory()->create(['phone' => '09123456786']);
        $shopper->assignRole('customer');

        $ids = collect($this->getJson('/api/v1/admin/users?role=delivery')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($ids->contains($courier->id));
        $this->assertFalse($ids->contains($shopper->id));
    }

    /** @test */
    public function creating_users_and_granting_delivery_are_permission_gated(): void
    {
        $payload = ['name' => 'X', 'phone' => '09123456787', 'role' => 'customer'];

        // Unauthenticated.
        $this->postJson('/api/v1/admin/users', $payload)->assertStatus(401);

        $target = User::factory()->create(['phone' => '09123456788']);
        $this->postJson("/api/v1/admin/users/{$target->id}/delivery-role")->assertStatus(401);

        // Authenticated but unauthorized.
        $this->actingAsCustomer();
        $this->postJson('/api/v1/admin/users', $payload)->assertStatus(403);
        $this->postJson("/api/v1/admin/users/{$target->id}/delivery-role")->assertStatus(403);
    }

    /** @test */
    public function create_permission_alone_cannot_mint_a_delivery_worker(): void
    {
        // A user who may create accounts but may not hand out delivery duty. The
        // two permissions are separable on purpose, so the create endpoint cannot
        // be used as a way around the grant endpoint.
        $operator = User::factory()->create(['phone' => '09123456700']);
        $operator->givePermissionTo('profile.create-any');
        $this->actingAs($operator, 'sanctum');

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Courier',
            'phone' => '09123456701',
            'role' => 'delivery',
        ])->assertStatus(403);

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Shopper',
            'phone' => '09123456702',
            'role' => 'customer',
        ])->assertStatus(201);
    }

    /** @test */
    public function the_authenticated_user_response_exposes_roles_and_permissions(): void
    {
        $courier = User::factory()->create(['phone' => '09123456703']);
        $courier->assignRole('customer');
        $courier->assignRole('delivery');
        $this->actingAs($courier, 'sanctum');

        $response = $this->getJson('/api/v1/me')->assertOk();

        $this->assertContains('delivery', $response->json('user.roles'));
        $this->assertContains('shipment.delivery.view-assigned', $response->json('user.permissions'));
    }
}
