<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Order\Domain\Models\Order;
use Modules\Ticket\Domain\Events\TicketCreatedEvent;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;
use Modules\Ticket\Domain\Events\TicketStatusChangedEvent;
use Modules\Ticket\Domain\Models\Ticket;
use Tests\TestCase;

/**
 * Customer-facing ticket flows: create, list, view, reply, close, references,
 * and the ownership/auth matrix.
 */
class TicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedTicketPermissions();
        $this->seedTicketCategories();

        // Silence only the ticket integration events (notifications are covered in
        // TicketNotificationTest). Faking ALL events would also mute Eloquent model
        // events and break public-code generation, so fake these by name only.
        Event::fake([
            TicketCreatedEvent::class,
            TicketReplyCreatedEvent::class,
            TicketStatusChangedEvent::class,
        ]);
    }

    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Payment problem',
            'category' => 'payment',
            'priority' => 'normal',
            'message' => 'My payment failed',
        ], $overrides);
    }

    /** @test */
    public function a_customer_can_create_a_ticket_with_a_first_message(): void
    {
        $user = $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/tickets', $this->createPayload());

        $response->assertCreated()
            ->assertJsonPath('data.subject', 'Payment problem')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.category', 'payment');

        $ticketNumber = $response->json('data.ticket_number');
        $this->assertMatchesRegularExpression('/^bdk-[0-9A-Z]{6}$/', $ticketNumber);

        $this->assertDatabaseHas('tickets', [
            'ticket_number' => $ticketNumber,
            'user_id' => $user->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('ticket_messages', [
            'user_id' => $user->id,
            'message' => 'My payment failed',
            'type' => 'customer_reply',
        ]);
    }

    /** @test */
    public function creating_a_ticket_requires_a_subject_and_a_message(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/tickets', $this->createPayload(['subject' => '', 'message' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'message']);
    }

    /** @test */
    public function an_unknown_category_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/tickets', $this->createPayload(['category' => 'does-not-exist']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    /** @test */
    public function a_customer_only_sees_their_own_tickets_in_the_list(): void
    {
        $mine = $this->actingAsCustomer();
        $this->postJson('/api/v1/tickets', $this->createPayload())->assertCreated();

        // Another customer's ticket.
        $other = User::factory()->create();
        $other->assignRole('customer');
        Ticket::create([
            'user_id' => $other->id,
            'subject' => 'Not yours',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/v1/tickets')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.user_id'));
    }

    /** @test */
    public function a_customer_cannot_view_another_users_ticket(): void
    {
        $other = User::factory()->create();
        $other->assignRole('customer');
        $ticket = Ticket::create([
            'user_id' => $other->id,
            'subject' => 'Secret',
            'priority' => 'normal',
            'status' => 'open',
        ]);

        $this->actingAsCustomer();

        $this->getJson("/api/v1/tickets/{$ticket->ticket_number}")->assertNotFound();
    }

    /** @test */
    public function a_customer_can_reply_to_their_ticket(): void
    {
        $this->actingAsCustomer();
        $number = $this->postJson('/api/v1/tickets', $this->createPayload())->json('data.ticket_number');

        $this->postJson("/api/v1/tickets/{$number}/messages", ['message' => 'Any update?'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'customer_reply')
            ->assertJsonPath('data.message', 'Any update?');

        $this->assertDatabaseHas('ticket_messages', ['message' => 'Any update?', 'type' => 'customer_reply']);
    }

    /** @test */
    public function a_customer_can_close_their_ticket(): void
    {
        $this->actingAsCustomer();
        $number = $this->postJson('/api/v1/tickets', $this->createPayload())->json('data.ticket_number');

        $this->postJson("/api/v1/tickets/{$number}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('tickets', ['ticket_number' => $number, 'status' => 'closed']);
        $this->assertNotNull(
            Ticket::where('ticket_number', $number)->first()->closed_at
        );
    }

    /** @test */
    public function a_customer_cannot_reply_to_a_closed_ticket(): void
    {
        $this->actingAsCustomer();
        $number = $this->postJson('/api/v1/tickets', $this->createPayload())->json('data.ticket_number');
        $this->postJson("/api/v1/tickets/{$number}/close")->assertOk();

        $this->postJson("/api/v1/tickets/{$number}/messages", ['message' => 'reopen please'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    }

    private function orderFor(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 100000,
            'shipping_cost' => 0,
            'tax_amount' => 0,
            'shipping_address' => ['address' => 'x'],
        ]);
    }

    /** @test */
    public function a_ticket_can_carry_multiple_references_the_customer_owns(): void
    {
        $user = $this->actingAsCustomer();
        $order = $this->orderFor($user);

        $number = $this->postJson('/api/v1/tickets', $this->createPayload([
            'references' => [
                ['type' => 'order', 'code' => $order->public_code],
                // Product is public — owned by nobody — so it needs no ownership check.
                ['type' => 'product', 'code' => 'bdp-XYZ789'],
            ],
        ]))->assertCreated()->json('data.ticket_number');

        $ticket = Ticket::where('ticket_number', $number)->first();
        $this->assertSame(2, $ticket->references()->count());
        $this->assertDatabaseHas('ticket_references', [
            'ticket_id' => $ticket->id,
            'reference_type' => 'order',
            'reference_code' => $order->public_code,
        ]);

        $this->getJson("/api/v1/tickets/{$number}")
            ->assertOk()
            ->assertJsonCount(2, 'data.references');
    }

    /** @test */
    public function a_customer_cannot_reference_an_order_belonging_to_someone_else(): void
    {
        $other = User::factory()->create();
        $other->assignRole('customer');
        $foreignOrder = $this->orderFor($other);

        $this->actingAsCustomer();

        $this->postJson('/api/v1/tickets', $this->createPayload([
            'references' => [['type' => 'order', 'code' => $foreignOrder->public_code]],
        ]))->assertStatus(422)->assertJsonValidationErrors(['references.0.code']);

        // Nothing was written.
        $this->assertDatabaseCount('tickets', 0);
        $this->assertDatabaseCount('ticket_references', 0);
    }

    /** @test */
    public function a_reference_to_a_nonexistent_order_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/tickets', $this->createPayload([
            'references' => [['type' => 'order', 'code' => 'bdo-ZZZ999']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['references.0.code']);
    }

    /** @test */
    public function an_unknown_reference_type_is_rejected(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/tickets', $this->createPayload([
            'references' => [['type' => 'invoice', 'code' => 'x']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['references.0.type']);
    }

    /** @test */
    public function creating_a_ticket_requires_authentication(): void
    {
        $this->postJson('/api/v1/tickets', $this->createPayload())->assertUnauthorized();
    }

    /** @test */
    public function a_user_without_the_create_permission_gets_403(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user); // no roles → no ticket.create

        $this->postJson('/api/v1/tickets', $this->createPayload())->assertForbidden();
    }
}
