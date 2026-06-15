<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Domain\Models\Media;
use Tests\TestCase;

/**
 * Covers the standalone Media upload/delete endpoints and their
 * permission-based authorization boundary.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedMediaPermissions();
        Storage::fake();
    }

    // ── Unauthenticated → 401 ───────────────────────────────────────────────

    /** @test */
    public function unauthenticated_request_cannot_upload(): void
    {
        $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('x.jpg')])
            ->assertUnauthorized();
    }

    /** @test */
    public function unauthenticated_request_cannot_delete(): void
    {
        $this->deleteJson('/api/v1/media/1')
            ->assertUnauthorized();
    }

    // ── Authenticated without permission → 403 ──────────────────────────────

    /** @test */
    public function customer_without_permission_cannot_upload(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/media', ['file' => UploadedFile::fake()->image('x.jpg')])
            ->assertForbidden();
    }

    /** @test */
    public function customer_without_permission_cannot_delete(): void
    {
        $this->actingAsCustomer();

        $this->deleteJson('/api/v1/media/1')
            ->assertForbidden();
    }

    // ── Happy path ──────────────────────────────────────────────────────────

    /** @test */
    public function user_with_upload_permission_can_upload_and_receives_id_and_url(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $response = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'url', 'mime_type', 'file_size', 'original_name']);

        $media = Media::find($response->json('id'));
        $this->assertNotNull($media);
        Storage::assertExists($media->file_path);
    }

    /** @test */
    public function upload_respects_a_custom_folder(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $response = $this->postJson('/api/v1/media', [
            'file'   => UploadedFile::fake()->image('photo.jpg'),
            'folder' => 'banners',
        ]);

        $response->assertCreated();

        $media = Media::find($response->json('id'));
        $this->assertStringContainsString('banners', $media->file_path);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /** @test */
    public function upload_without_a_file_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $this->postJson('/api/v1/media', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    /** @test */
    public function upload_of_a_non_image_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    /** @test */
    public function upload_with_a_traversal_folder_is_rejected(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $this->postJson('/api/v1/media', [
            'file'   => UploadedFile::fake()->image('photo.jpg'),
            'folder' => '../etc',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('folder');
    }

    // ── Delete ──────────────────────────────────────────────────────────────

    /** @test */
    public function user_with_delete_permission_can_delete_and_the_file_is_removed(): void
    {
        $uploader = $this->actingAsCustomer();
        $uploader->givePermissionTo('media.upload');

        $id = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->json('id');

        $path = Media::find($id)->file_path;
        Storage::assertExists($path);

        $uploader->givePermissionTo('media.delete');

        $this->deleteJson("/api/v1/media/{$id}")
            ->assertNoContent();

        $this->assertNull(Media::find($id));
        Storage::assertMissing($path);
    }

    /** @test */
    public function deleting_an_unknown_media_returns_404(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.delete');

        $this->deleteJson('/api/v1/media/99999')
            ->assertNotFound();
    }

    // ── Permission-based (not role-based) proof ─────────────────────────────

    /** @test */
    public function upload_permission_does_not_grant_delete(): void
    {
        $user = $this->actingAsCustomer();
        $user->givePermissionTo('media.upload');

        $id = $this->postJson('/api/v1/media', [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->json('id');

        $this->deleteJson("/api/v1/media/{$id}")
            ->assertForbidden();
    }
}
