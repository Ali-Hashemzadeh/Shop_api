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
        $this->seedIdentityRolesAndPermissions();
        $this->actingAsAdmin();
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
        $this->postJson('/api/v1/catalog/categories', ['name' => 'Home & Garden'])
            ->assertCreated()
            ->assertJsonPath('slug', 'home-garden');
    }

    /** @test */
    public function it_accepts_a_custom_slug(): void
    {
        $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Electronics',
            'slug' => 'my-custom-slug',
        ])->assertCreated()
            ->assertJsonPath('slug', 'my-custom-slug');
    }

    /** @test */
    public function it_can_create_a_subcategory_under_a_parent(): void
    {
        $parent = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->postJson('/api/v1/catalog/categories', [
            'name'      => 'Phones',
            'parent_id' => $parent->id,
        ])->assertCreated()
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
        $this->postJson('/api/v1/catalog/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    /** @test */
    public function it_rejects_a_duplicate_slug(): void
    {
        Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->postJson('/api/v1/catalog/categories', [
            'name' => 'Electronics 2',
            'slug' => 'electronics',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    /** @test */
    public function it_rejects_providing_both_media_id_and_image_file(): void
    {
        $this->postJson('/api/v1/catalog/categories', [
            'name'     => 'Electronics',
            'media_id' => 1,
            'image'    => UploadedFile::fake()->image('electronics.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrorFor('media_id');
    }

    // ── PATCH /api/v1/catalog/categories/{id} ────────────────────────────────

    /** @test */
    public function it_can_update_a_category_name(): void
    {
        $category = Category::create(['name' => 'Old Name', 'slug' => 'old-name', 'is_active' => true]);

        $this->patchJson("/api/v1/catalog/categories/{$category->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('name', 'New Name');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'New Name']);
    }

    /** @test */
    public function it_can_deactivate_a_category(): void
    {
        $category = Category::create(['name' => 'Active', 'slug' => 'active', 'is_active' => true]);

        $this->patchJson("/api/v1/catalog/categories/{$category->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'is_active' => false]);
    }

    /** @test */
    public function it_can_update_a_category_with_a_new_image(): void
    {
        $category = Category::create(['name' => 'Clothing', 'slug' => 'clothing', 'is_active' => true]);

        $response = $this->patchJson("/api/v1/catalog/categories/{$category->id}", [
            'image' => UploadedFile::fake()->image('new-clothing.jpg'),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('image_url'));
    }

    /** @test */
    public function it_allows_patching_with_the_same_slug_the_category_already_has(): void
    {
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->patchJson("/api/v1/catalog/categories/{$category->id}", ['slug' => 'electronics'])
            ->assertOk();
    }

    /** @test */
    public function it_rejects_updating_a_slug_already_taken_by_another_category(): void
    {
        Category::create(['name' => 'Books', 'slug' => 'books', 'is_active' => true]);
        $category = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);

        $this->patchJson("/api/v1/catalog/categories/{$category->id}", ['slug' => 'books'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    /** @test */
    public function it_returns_404_when_updating_a_non_existent_category(): void
    {
        $this->patchJson('/api/v1/catalog/categories/99999', ['name' => 'Ghost'])
            ->assertNotFound();
    }

    // ── DELETE /api/v1/catalog/categories/{id} ───────────────────────────────

    /** @test */
    public function it_can_delete_a_category(): void
    {
        $category = Category::create(['name' => 'Doomed', 'slug' => 'doomed', 'is_active' => true]);

        $this->deleteJson("/api/v1/catalog/categories/{$category->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /** @test */
    public function it_returns_404_when_deleting_a_non_existent_category(): void
    {
        $this->deleteJson('/api/v1/catalog/categories/99999')
            ->assertNotFound();
    }

    // ── GET /api/v1/catalog/categories/{id} ──────────────────────────────────

    /** @test */
    public function it_can_fetch_a_category_by_id(): void
    {
        $category = Category::create(['name' => 'Books', 'slug' => 'books', 'is_active' => true]);

        $this->getJson("/api/v1/catalog/categories/{$category->id}")
            ->assertOk()
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
        Category::create(['name' => 'Child', 'slug' => 'child', 'is_active' => true, 'parent_id' => $parent->id]);
        Category::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

        $response = $this->getJson('/api/v1/catalog/categories/roots');

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertCount(2, $response->json('data'));
    }
}
