<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Domain\Models\Category;
use Tests\TestCase;

class CategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
    }

    // ── POST /api/v1/catalog/categories ──────────────────────────────────────

    /** @test */
    public function it_can_create_a_category_with_minimal_data(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Electronics',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'name', 'slug', 'is_active', 'parent_id', 'image_url']);

        $this->assertDatabaseHas('categories', ['name' => 'Electronics']);
    }

    /** @test */
    public function it_auto_generates_a_slug_from_the_name(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Home & Garden',
        ]);

        $response->assertCreated()
            ->assertJsonPath('slug', 'home-garden');
    }

    /** @test */
    public function it_accepts_a_custom_slug(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Electronics',
            'slug' => 'my-custom-slug',
        ]);

        $response->assertCreated()
            ->assertJsonPath('slug', 'my-custom-slug');
    }

    /** @test */
    public function it_can_create_a_subcategory_under_a_parent(): void
    {
        $parent = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $response = $this->postJson('/api/v1/catalog/categories', [
            'name'      => 'Phones',
            'parent_id' => $parent->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('parent_id', $parent->id);
    }

    /** @test */
    public function it_can_create_a_category_with_an_uploaded_image(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', [
            'name'  => 'Clothing',
            'image' => UploadedFile::fake()->image('clothing.jpg'),
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('image_url'));
    }

    /** @test */
    public function it_requires_a_name_to_create_a_category(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function it_rejects_a_duplicate_slug(): void
    {
        Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $response = $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Electronics 2',
            'slug' => 'electronics',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    /** @test */
    public function it_rejects_providing_both_media_id_and_image_file(): void
    {
        $response = $this->postJson('/api/v1/catalog/categories', [
            'name'     => 'Electronics',
            'media_id' => 1,
            'image'    => UploadedFile::fake()->image('electronics.jpg'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrorFor('media_id');
    }

    // ── GET /api/v1/catalog/categories/{id} ──────────────────────────────────

    /** @test */
    public function it_can_fetch_a_category_by_id(): void
    {
        $category = Category::create(['name' => 'Books', 'slug' => 'books', 'is_active' => true]);

        $response = $this->getJson("/api/v1/catalog/categories/{$category->id}");

        $response->assertOk()
            ->assertJsonPath('id', $category->id)
            ->assertJsonPath('name', 'Books')
            ->assertJsonPath('slug', 'books')
            ->assertJsonPath('is_active', true);
    }

    /** @test */
    public function it_returns_404_when_category_is_not_found(): void
    {
        $this->getJson('/api/v1/catalog/categories/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/categories/roots ─────────────────────────────────

    /** @test */
    public function it_can_list_active_root_categories(): void
    {
        $parent = Category::create(['name' => 'Root A', 'slug' => 'root-a', 'is_active' => true]);
        Category::create(['name' => 'Root B', 'slug' => 'root-b', 'is_active' => true]);
        // Child should not appear in roots listing
        Category::create(['name' => 'Child', 'slug' => 'child', 'is_active' => true, 'parent_id' => $parent->id]);
        // Inactive root should not appear
        Category::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

        $response = $this->getJson('/api/v1/catalog/categories/roots');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }
}
