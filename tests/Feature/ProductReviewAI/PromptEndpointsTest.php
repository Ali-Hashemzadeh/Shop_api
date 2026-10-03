<?php

namespace Tests\Feature\ProductReviewAI;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\AiPromptSeeder;
use Modules\ProductReviewAI\Infrastructure\Persistence\Seeders\ProductReviewAIPermissionsSeeder;
use Tests\TestCase;

class PromptEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seed(ProductReviewAIPermissionsSeeder::class);
        $this->seed(AiPromptSeeder::class);
    }

    public function test_admin_can_list_prompts(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/admin/ai-prompts')->assertOk();

        // Seeded: review_analysis + review_generation (v1 each).
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('review_generation'));
        $this->assertArrayHasKey('system_prompt', $response->json('data.0'));
    }

    public function test_admin_can_filter_prompts_by_name_and_view_one(): void
    {
        $this->actingAsAdmin();

        $list = $this->getJson('/api/v1/admin/ai-prompts?name=review_generation&active=1')->assertOk();
        $this->assertSame('review_generation', $list->json('data.0.name'));

        $id = $list->json('data.0.id');
        $this->getJson("/api/v1/admin/ai-prompts/{$id}")
            ->assertOk()
            ->assertJsonPath('name', 'review_generation')
            ->assertJsonPath('version', 1);
    }

    public function test_customer_cannot_view_prompts(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/admin/ai-prompts')->assertStatus(403);
    }

    public function test_guest_cannot_view_prompts(): void
    {
        $this->getJson('/api/v1/admin/ai-prompts')->assertStatus(401);
    }
}
