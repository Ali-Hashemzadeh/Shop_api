<?php

declare(strict_types=1);

namespace Tests\Feature\Blog;

use App\Support\PublicCodeEntity;
use App\Support\PublicCodeGenerator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Persistence\Seeders\BlogPermissionsSeeder;
use Modules\Blog\Infrastructure\Persistence\Seeders\BlogSampleDataSeeder;
use Modules\Identity\Domain\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Seeders: permissions land on the right roles, and demo content is built
 * through real Actions under WithoutModelEvents (as DatabaseSeeder runs it),
 * idempotently.
 */
class BlogSeederTest extends BlogTestCase
{
    /** @test */
    public function permissions_are_granted_to_admin_and_only_comment_create_to_customers(): void
    {
        $this->seed(BlogPermissionsSeeder::class); // re-run: idempotent

        $admin = Role::findByName('admin');
        foreach ([...BlogPermissionsSeeder::ADMIN_PERMISSIONS, ...BlogPermissionsSeeder::CUSTOMER_PERMISSIONS] as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission), "admin lacks {$permission}");
        }

        $customer = Role::findByName('customer');
        $this->assertTrue($customer->hasPermissionTo('blog.comment.create'));
        $this->assertFalse($customer->hasPermissionTo('blog.view-admin'));
        $this->assertFalse($customer->hasPermissionTo('blog.post.publish'));
    }

    /** @test */
    public function sample_data_is_real_and_idempotent_under_muted_model_events(): void
    {
        User::factory()->create()->assignRole('admin');

        $runner = new class extends Seeder
        {
            use WithoutModelEvents;

            public function run(): void
            {
                $this->call(BlogSampleDataSeeder::class);
            }
        };

        $this->seed($runner::class);
        $count = BlogPost::query()->count();
        $this->seed($runner::class);

        $this->assertSame($count, BlogPost::query()->count());
        $this->assertSame(4, $count);

        foreach (BlogPost::all() as $post) {
            $this->assertTrue(PublicCodeGenerator::matches($post->public_code, PublicCodeEntity::BlogPost));
        }

        $this->getJson('/api/v1/blog/posts')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/blog/collections/editors-picks')->assertOk()->assertJsonCount(2, 'data.posts');
        $this->getJson('/api/v1/blog/posts/how-to-choose-a-phone/comments')->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('blog_posts', ['slug' => 'nowruz-delivery-schedule', 'status' => 'scheduled']);
    }
}
