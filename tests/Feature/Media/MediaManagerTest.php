<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\DTOs\MediaDTO;
use Modules\Media\Domain\Models\Media;
use Tests\TestCase;

class MediaManagerTest extends TestCase
{
    use RefreshDatabase;

    private MediaManagerInterface $mediaManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve the interface from Laravel's service container
        $this->mediaManager = $this->app->make(MediaManagerInterface::class);

        // Fake the public disk so uploads stay in memory during tests
        Storage::fake('public');
    }

    /** @test */
    public function it_can_upload_a_file_physically_and_log_it_in_the_database()
    {
        // Arrange
        $fakeFile = UploadedFile::fake()->image('test-product-image.jpg');

        // Act
        $dto = $this->mediaManager->upload($fakeFile, 'products');

        // Assert: Verify data integrity returned via the DTO
        $this->assertInstanceOf(MediaDTO::class, $dto);
        $this->assertGreaterThan(0, $dto->id);
        $this->assertEquals('test-product-image.jpg', $dto->originalName);
        $this->assertEquals('image/jpeg', $dto->mimeType);

        // Assert: Check that the Eloquent record exists in the database ledger
        $this->assertDatabaseHas('media', [
            'id' => $dto->id,
            'original_name' => 'test-product-image.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        // Assert: Check that the file was physically saved to the correct mock directory
        $mediaRecord = Media::find($dto->id);
        Storage::disk('public')->assertExists($mediaRecord->file_path);
    }

    /** @test */
    public function it_can_retrieve_a_single_media_dto_by_its_id()
    {
        // Arrange
        $media = Media::create([
            'file_path' => 'public/products/sample.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'original_name' => 'sample.jpg',
        ]);

        // Act
        $dto = $this->mediaManager->getMedia($media->id);

        // Assert
        $this->assertNotNull($dto);
        $this->assertInstanceOf(MediaDTO::class, $dto);
        $this->assertEquals($media->id, $dto->id);
        $this->assertEquals('image/jpeg', $dto->mimeType);
    }

    /** @test */
    public function it_returns_null_when_retrieving_non_existent_media()
    {
        // Act
        $dto = $this->mediaManager->getMedia(99999);

        // Assert
        $this->assertNull($dto);
    }

    /** @test */
    public function it_can_retrieve_a_collection_of_media_dtos_by_multiple_ids()
    {
        // Arrange
        $media1 = Media::create(['file_path' => 'public/products/img1.jpg', 'original_name' => 'img1.jpg']);
        $media2 = Media::create(['file_path' => 'public/products/img2.jpg', 'original_name' => 'img2.jpg']);
        $media3 = Media::create(['file_path' => 'public/products/img3.jpg', 'original_name' => 'img3.jpg']);

        // Act
        $collection = $this->mediaManager->getMediaCollection([$media1->id, $media3->id]);

        // Assert
        $this->assertCount(2, $collection);
        $this->assertEquals($media1->id, $collection->first()->id);
        $this->assertEquals($media3->id, $collection->last()->id);
    }

    /** @test */
    public function it_can_physically_delete_a_file_and_clear_its_database_record()
    {
        // Arrange: Seed an asset through the manager to create a real mock file
        $fakeFile = UploadedFile::fake()->image('delete-me.png');
        $dto = $this->mediaManager->upload($fakeFile, 'products');

        $mediaRecord = Media::find($dto->id);
        Storage::disk('public')->assertExists($mediaRecord->file_path);

        // Act
        $result = $this->mediaManager->delete($dto->id);

        // Assert
        $this->assertTrue($result);
        $this->assertDatabaseMissing('media', ['id' => $dto->id]);
        Storage::disk('public')->assertMissing($mediaRecord->file_path);
    }
}
