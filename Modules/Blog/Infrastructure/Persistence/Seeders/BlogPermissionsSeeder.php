<?php

namespace Modules\Blog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Blog permissions. Admin receives every one; customers receive only
 * `blog.comment.create` (likes, bookmarks, follows and reports are self-service
 * and need authentication only).
 *
 * Roles are permission bundles: future `blog_author` / `blog_editor` /
 * `blog_moderator` roles are just new bundles of these names — no Blog code
 * checks a role. They are deliberately not created here (additive, like
 * `support`/`delivery`, only when the business needs them).
 */
class BlogPermissionsSeeder extends Seeder
{
    /** @var list<string> */
    public const ADMIN_PERMISSIONS = [
        'blog.view-admin',
        'blog.post.create',
        'blog.post.update-own',
        'blog.post.update-any',
        'blog.post.delete',
        'blog.post.restore',
        'blog.post.submit-review',
        'blog.post.approve',
        'blog.post.publish',
        'blog.post.archive',
        'blog.category.manage',
        'blog.tag.manage',
        'blog.author.manage',
        'blog.collection.manage',
        'blog.placement.manage',
        'blog.comment.view-admin',
        'blog.comment.moderate',
        'blog.comment.delete',
        'blog.analytics.view',
    ];

    /** @var list<string> */
    public const CUSTOMER_PERMISSIONS = [
        'blog.comment.create',
    ];

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $all = [...self::ADMIN_PERMISSIONS, ...self::CUSTOMER_PERMISSIONS];

        foreach ($all as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        Role::where('name', 'admin')->where('guard_name', 'web')->first()?->givePermissionTo($all);
        Role::where('name', 'customer')->where('guard_name', 'web')->first()?->givePermissionTo(self::CUSTOMER_PERMISSIONS);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
