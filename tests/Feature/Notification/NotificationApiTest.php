<?php

namespace Tests\Feature\Notification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Identity\Domain\Models\User;
use Modules\Notification\Domain\Models\Notification;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedNotificationPermissions();
    }

    private function createNotification(int $userId, string $type = 'payment_success'): Notification
    {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => 'پرداخت موفق',
            'message' => 'پرداخت سفارش شما با موفقیت انجام شد.',
            'data' => ['order_id' => 123],
        ]);
    }

    // ── Listing ───────────────────────────────────────────────────────────────

    /** @test */
    public function an_authenticated_user_can_list_their_own_notifications(): void
    {
        $user = $this->actingAsCustomer();
        $notification = $this->createNotification($user->id);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $notification->id)
            ->assertJsonPath('data.0.type', 'payment_success')
            ->assertJsonPath('data.0.title', 'پرداخت موفق')
            ->assertJsonPath('data.0.data.order_id', 123)
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    /** @test */
    public function a_user_never_sees_another_users_notifications(): void
    {
        $other = User::factory()->create();
        $this->createNotification($other->id, 'order_cancelled');

        $user = $this->actingAsCustomer();
        $own = $this->createNotification($user->id);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    /** @test */
    public function the_list_endpoint_never_exposes_delivery_internals(): void
    {
        $user = $this->actingAsCustomer();
        $this->createNotification($user->id);

        $response = $this->getJson('/api/v1/notifications')->assertOk();

        $this->assertSame(
            ['id', 'type', 'title', 'message', 'data', 'read_at', 'created_at'],
            array_keys($response->json('data.0'))
        );
    }

    /** @test */
    public function guests_cannot_list_notifications(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    // ── Marking as read ───────────────────────────────────────────────────────

    /** @test */
    public function a_user_can_mark_their_own_notification_as_read(): void
    {
        $user = $this->actingAsCustomer();
        $notification = $this->createNotification($user->id);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('id', $notification->id)
            ->assertJsonPath('read_at', fn ($readAt) => $readAt !== null);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /** @test */
    public function marking_an_already_read_notification_keeps_the_original_timestamp(): void
    {
        $user = $this->actingAsCustomer();
        $notification = $this->createNotification($user->id);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $firstReadAt = $notification->fresh()->read_at;

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

        $this->assertEquals($firstReadAt, $notification->fresh()->read_at);
    }

    /** @test */
    public function a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $other = User::factory()->create();
        $foreign = $this->createNotification($other->id);

        $this->actingAsCustomer();

        $this->postJson("/api/v1/notifications/{$foreign->id}/read")->assertStatus(403);

        $this->assertNull($foreign->fresh()->read_at);
    }

    /** @test */
    public function marking_a_missing_notification_returns_404(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/notifications/999999/read')->assertStatus(404);
    }

    /** @test */
    public function guests_cannot_mark_a_notification_as_read(): void
    {
        $user = User::factory()->create();
        $notification = $this->createNotification($user->id);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(401);
    }
}
