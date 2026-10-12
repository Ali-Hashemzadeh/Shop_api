<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Blog\Domain\Models\BlogCategory;

/**
 * Delete a category only when nothing depends on it. A category with
 * sub-categories or attached posts (including soft-deleted posts, which may be
 * restored) is refused with 422 — deactivate it instead. This keeps every post's
 * primary category and every breadcrumb trail consistent.
 */
final class DeleteBlogCategoryAction
{
    public function handle(BlogCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            if (BlogCategory::query()->where('parent_id', $category->id)->exists()) {
                throw ValidationException::withMessages([
                    'category' => 'This category has sub-categories. Move or delete them first, or deactivate it.',
                ]);
            }

            if (DB::table('blog_post_category')->where('blog_category_id', $category->id)->exists()) {
                throw ValidationException::withMessages([
                    'category' => 'This category is attached to posts. Detach it from them first, or deactivate it.',
                ]);
            }

            DB::table('blog_subscriptions')->where('subject_type', 'category')->where('subject_id', $category->id)->delete();
            $category->delete();
        });
    }
}
