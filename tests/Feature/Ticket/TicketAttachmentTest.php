<?php

declare(strict_types=1);

namespace Tests\Feature\Ticket;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Ticket\Domain\Events\TicketCreatedEvent;
use Modules\Ticket\Domain\Events\TicketReplyCreatedEvent;
use Modules\Ticket\Domain\Models\Ticket;
use Tests\TestCase;

/**
 * Ticket message attachments, built on the existing Media system (pre-uploaded
 * media referenced by id), including the ownership-security matrix.
 */
class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedMediaPermissions();
        $this->seedTicketPermissions();
        $this->seedTicketCategories();

        Storage::fake('public');
        // Attachment notifications are covered elsewhere; silence ticket events.
        Event::fake([TicketCreatedEvent::class, TicketReplyCreatedEvent::class]);
    }

    /** Upload an image as the given user (needs `media.upload`) and return its id. */
    private function uploadAs(User $user): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('screenshot.jpg'),
        ])->assertCreated()->json('id');
    }

    private function customerWhoCanUpload(): User
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        $user->givePermissionTo('media.upload'); // per-user, matching the project convention

        return $user;
    }

    /** @test */
    public function a_customer_can_create_a_ticket_with_an_attachment(): void
    {
        $user = $this->customerWhoCanUpload();
        $mediaId = $this->uploadAs($user);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/tickets', [
            'subject' => 'Screenshot attached',
            'category' => 'payment',
            'priority' => 'normal',
            'message' => 'Here is the error',
            'media_ids' => [$mediaId],
        ])->assertCreated();

        $response->assertJsonPath('data.messages.0.attachments.0.id', $mediaId);
        $this->assertNotEmpty($response->json('data.messages.0.attachments.0.url'));

        $ticket = Ticket::where('ticket_number', $response->json('data.ticket_number'))->first();
        $this->assertSame([$mediaId], $ticket->messages()->first()->media_ids);
    }

    /** @test */
    public function a_customer_can_reply_with_an_attachment(): void
    {
        $user = $this->customerWhoCanUpload();
        $ticket = Ticket::create([
            'user_id' => $user->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'open',
        ]);
        $mediaId = $this->uploadAs($user);

        Sanctum::actingAs($user);
        $this->postJson("/api/v1/tickets/{$ticket->ticket_number}/messages", [
            'message' => 'More detail',
            'media_ids' => [$mediaId],
        ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.id', $mediaId)
            ->assertJsonCount(1, 'data.attachments');
    }

    /** @test */
    public function a_customer_cannot_attach_another_users_media(): void
    {
        $owner = $this->customerWhoCanUpload();
        $foreignMediaId = $this->uploadAs($owner);

        // A different customer tries to attach the first customer's media.
        $attacker = User::factory()->create();
        $attacker->assignRole('customer');
        Sanctum::actingAs($attacker);

        $this->postJson('/api/v1/tickets', [
            'subject' => 'Hijack',
            'category' => 'payment',
            'priority' => 'normal',
            'message' => 'not mine',
            'media_ids' => [$foreignMediaId],
        ])->assertStatus(422)->assertJsonValidationErrors(['media_ids']);

        $this->assertDatabaseCount('tickets', 0);
    }

    /** @test */
    public function a_nonexistent_media_id_is_rejected(): void
    {
        $user = $this->customerWhoCanUpload();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tickets', [
            'subject' => 'Ghost',
            'category' => 'payment',
            'priority' => 'normal',
            'message' => 'no such file',
            'media_ids' => [999999],
        ])->assertStatus(422)->assertJsonValidationErrors(['media_ids']);
    }

    /** @test */
    public function a_support_agent_can_reply_with_an_attachment(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $agent = User::factory()->create();
        $agent->assignRole('customer');
        $agent->assignRole('support');
        $agent->givePermissionTo('media.upload');

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $agent->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'in_progress',
        ]);

        $mediaId = $this->uploadAs($agent);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/messages", [
            'message' => 'See attached fix',
            'media_ids' => [$mediaId],
        ])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.id', $mediaId);
    }

    /** @test */
    public function an_internal_note_attachment_is_never_visible_to_the_customer(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        $agent = User::factory()->create();
        $agent->assignRole('customer');
        $agent->assignRole('support');
        $agent->givePermissionTo('media.upload');

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'assigned_to' => $agent->id,
            'subject' => 'Help',
            'priority' => 'normal',
            'status' => 'in_progress',
        ]);

        $mediaId = $this->uploadAs($agent);

        Sanctum::actingAs($agent);
        $this->postJson("/api/v1/support/tickets/{$ticket->ticket_number}/notes", [
            'message' => 'internal evidence',
            'media_ids' => [$mediaId],
        ])->assertCreated()->assertJsonPath('data.attachments.0.id', $mediaId);

        // The customer sees neither the note nor its attachment.
        Sanctum::actingAs($customer);
        $view = $this->getJson("/api/v1/tickets/{$ticket->ticket_number}")->assertOk();

        $types = collect($view->json('data.messages'))->pluck('type')->all();
        $this->assertNotContains('internal_note', $types);

        $attachmentIds = collect($view->json('data.messages'))
            ->flatMap(fn ($m) => collect($m['attachments'] ?? [])->pluck('id'))
            ->all();
        $this->assertNotContains($mediaId, $attachmentIds);
    }

    /** @test */
    public function a_ticket_without_attachments_still_works(): void
    {
        $user = User::factory()->create();
        $user->assignRole('customer');
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tickets', [
            'subject' => 'No files',
            'category' => 'general',
            'priority' => 'normal',
            'message' => 'plain text only',
        ])
            ->assertCreated()
            ->assertJsonCount(0, 'data.messages.0.attachments');
    }
}
