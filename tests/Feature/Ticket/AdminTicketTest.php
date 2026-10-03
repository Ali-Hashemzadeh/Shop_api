<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Identity\Domain\Models\User;
use Modules\Ticket\Domain\Events\TicketAssignedEvent;
use Modules\Ticket\Domain\Models\Ticket;
use Tests\TestCase;

/**
 * Admin flows: view-all, assignment (and its support-role guard), and support-user
 * role management.
 */
class AdminTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedTicketPermissions();
        // Fake only the ticket event (not all events — that would mute the
        // public-code Eloquent hook); notifications are covered separately.
        Event::fake([TicketAssignedEvent::class]);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');

        return $user;
    }

    private function supportAgent(): User
    {
        $user = User::factory()->create(['phone' => '09120000000']);
        $user->assignRole('customer');
        $user->assignRole('support');

        return $user;
    }

    private function ticket(User $customer): Ticket
    {
        return Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'open',
        ]);
    }

    /** @test */
    public function an_admin_sees_every_ticket(): void
    {
        $this->ticket($this->customer());
        $this->ticket($this->customer());

        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/tickets')->assertOk()->assertJsonCount(2, 'data');
    }

    /** @test */
    public function an_admin_can_assign_a_ticket_to_a_support_user(): void
    {
        $ticket = $this->ticket($this->customer());
        $agent = $this->supportAgent();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/tickets/{$ticket->ticket_number}/assign", ['user_id' => $agent->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to', $agent->id)
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $agent->id]);
    }

    /** @test */
    public function an_admin_cannot_assign_a_ticket_to_a_non_support_user(): void
    {
        $ticket = $this->ticket($this->customer());
        $plainCustomer = $this->customer();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/tickets/{$ticket->ticket_number}/assign", ['user_id' => $plainCustomer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    /** @test */
    public function an_admin_can_grant_the_support_role(): void
    {
        $user = $this->customer();
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/users/{$user->id}/roles/support")
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertTrue($user->fresh()->hasRole('support'));
        // Additive: the existing customer role survives.
        $this->assertTrue($user->fresh()->hasRole('customer'));
    }

    /** @test */
    public function an_admin_can_revoke_the_support_role(): void
    {
        $agent = $this->supportAgent();
        $this->actingAsAdmin();

        $this->deleteJson("/api/v1/admin/users/{$agent->id}/roles/support")->assertOk();

        $this->assertFalse($agent->fresh()->hasRole('support'));
        $this->assertTrue($agent->fresh()->hasRole('customer'));
    }

    /** @test */
    public function the_support_users_list_returns_only_support_agents(): void
    {
        $agent = $this->supportAgent();
        $this->customer(); // must not appear
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/support-users')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($agent->id, $ids);
        $this->assertCount(1, $ids);
    }

    /** @test */
    public function support_user_management_is_admin_only(): void
    {
        $agent = $this->supportAgent();
        $this->actingAsCustomer();

        $this->postJson("/api/v1/admin/users/{$agent->id}/roles/support")->assertForbidden();
        $this->getJson('/api/v1/admin/support-users')->assertForbidden();
    }
}
