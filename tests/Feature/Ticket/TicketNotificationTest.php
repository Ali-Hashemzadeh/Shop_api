<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Sms\Infrastructure\Drivers\FakeSmsProvider;
use Modules\Ticket\Domain\Models\Ticket;
use Tests\TestCase;

/**
 * Ticket flow → integration event → Notification listener → channels.
 *
 * Proves the wiring goes through the existing Notification/SMS abstraction
 * (FakeSmsProvider), never a Ticket-owned notifier. Ticket dispatches
 * primitives-only events; the listeners live in the Notification module.
 */
class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    private FakeSmsProvider $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedTicketPermissions();
        $this->seedTicketCategories();
        $this->seedNotificationPermissions();

        Http::preventStrayRequests();
        config()->set('sms.default', 'fake');
        $this->sms = app(FakeSmsProvider::class);
        $this->sms->reset();
    }

    private function makeCustomer(string $phone): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->assignRole('customer');

        return $user;
    }

    private function makeSupport(string $phone): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->assignRole('customer');
        $user->assignRole('support');

        return $user;
    }

    /** @test */
    public function opening_a_ticket_notifies_the_support_team(): void
    {
        $agent = $this->makeSupport('09120000001');
        $customer = $this->makeCustomer('09121111111');
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/tickets', [
            'subject' => 'Help',
            'category' => 'general',
            'priority' => 'normal',
            'message' => 'Please help',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'type' => 'ticket_created',
        ]);

        $this->assertNotEmpty($this->sms->sent());
        $this->assertSame('ticket_created', $this->sms->lastMessage()->template);
    }

    /** @test */
    public function a_staff_reply_notifies_the_customer(): void
    {
        $agent = $this->makeSupport('09120000002');
        $customer = $this->makeCustomer('09122222222');
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $agent->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/messages", ['message' => 'Answered'])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
        ]);

        $this->assertSame('ticket_reply', $this->sms->lastMessage()->template);
        $this->assertSame('09122222222', $this->sms->lastMessage()->receiver);
    }

    /** @test */
    public function an_internal_note_never_notifies_the_customer(): void
    {
        $agent = $this->makeSupport('09120000003');
        $customer = $this->makeCustomer('09123333333');
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $agent->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/notes", ['message' => 'internal only'])
            ->assertCreated();

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $customer->id,
            'type' => 'ticket_reply',
        ]);
    }

    /** @test */
    public function assigning_a_ticket_notifies_the_assignee(): void
    {
        $agent = $this->makeSupport('09120000004');
        $customer = $this->makeCustomer('09124444444');
        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAsAdmin();
        $this->postJson("/api/v1/admin/tickets/{$ticket->ticket_number}/assign", ['user_id' => $agent->id])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->id,
            'type' => 'ticket_assigned',
        ]);
        $this->assertSame('ticket_assigned', $this->sms->lastMessage()->template);
    }
}
