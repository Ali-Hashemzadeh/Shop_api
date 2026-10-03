<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;
use Modules\Ticket\Domain\Events\TicketStatusChangedEvent;
use Modules\Ticket\Domain\Models\Ticket;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Support-agent flows: scoped visibility, reply, status change, internal notes,
 * and the invisibility of those notes to the customer.
 */
class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedTicketPermissions();
        // Fake only the ticket events (not all events — that would break the
        // public-code Eloquent hook); notifications are covered separately.
        Event::fake([TicketReplyCreatedEvent::class, TicketStatusChangedEvent::class]);
    }

    private function ticketFor(User $customer, ?int $assignedTo = null): Ticket
    {
        return Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $assignedTo,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => $assignedTo ? 'in_progress' : 'open',
        ]);
    }

    /** @test */
    public function the_support_role_exists_and_carries_only_agent_permissions(): void
    {
        $support = Role::where('name', 'support')->where('guard_name', 'web')->first();

        $this->assertNotNull($support);
        $names = $support->permissions->pluck('name')->sort()->values()->all();
        $this->assertSame([
            'ticket.add-internal-note',
            'ticket.change-status',
            'ticket.reply-admin',
            'ticket.view-assigned',
        ], $names);
    }

    /** @test */
    public function a_support_user_sees_only_tickets_assigned_to_them(): void
    {
        $customer = User::factory()->create();
        $agent = $this->actingAsSupport();

        $mine = $this->ticketFor($customer, $agent->id);
        $this->ticketFor($customer, null); // unassigned

        $response = $this->getJson('/api/v1/support/tickets')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->ticket_number, $response->json('data.0.ticket_number'));
    }

    /** @test */
    public function a_support_user_cannot_view_a_ticket_not_assigned_to_them(): void
    {
        $customer = User::factory()->create();
        $this->actingAsSupport();
        $foreign = $this->ticketFor($customer, null);

        $this->getJson("/api/v1/support/tickets/{$foreign->ticket_number}")->assertNotFound();
    }

    /** @test */
    public function a_support_user_can_reply_to_an_assigned_ticket(): void
    {
        $customer = User::factory()->create();
        $agent = $this->actingAsSupport();
        $ticket = $this->ticketFor($customer, $agent->id);

        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/messages", ['message' => 'On it'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'admin_reply');

        // A staff reply flips the ticket to "answered".
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'answered']);
    }

    /** @test */
    public function a_support_user_can_change_the_status_of_an_assigned_ticket(): void
    {
        $customer = User::factory()->create();
        $agent = $this->actingAsSupport();
        $ticket = $this->ticketFor($customer, $agent->id);

        // Accepts the constant form and normalizes it.
        $this->patchJson("/api/v1/support/tickets/{$ticket->ticket_number}/status", ['status' => 'WAITING_FOR_CUSTOMER'])
            ->assertOk()
            ->assertJsonPath('data.status', 'waiting_for_customer');

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'waiting_for_customer']);
    }

    /** @test */
    public function internal_notes_are_visible_to_staff_but_never_to_the_customer(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $agent = $this->actingAsSupport();
        $ticket = $this->ticketFor($customer, $agent->id);

        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/notes", ['message' => 'Fraud suspected'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'internal_note');

        // The agent sees the note.
        $staffView = $this->getJson("/api/v1/support/tickets/{$ticket->ticket_number}")->assertOk();
        $this->assertContains('internal_note', collect($staffView->json('data.messages'))->pluck('type')->all());

        // The customer never sees it.
        Sanctum::actingAs($customer);
        $customerView = $this->getJson("/api/v1/tickets/{$ticket->ticket_number}")->assertOk();
        $this->assertNotContains('internal_note', collect($customerView->json('data.messages'))->pluck('type')->all());
    }

    /** @test */
    public function a_customer_without_support_role_cannot_reach_the_support_surface(): void
    {
        $this->actingAsCustomer(); // no ticket.view-assigned

        $this->getJson('/api/v1/support/tickets')->assertForbidden();
    }
}
