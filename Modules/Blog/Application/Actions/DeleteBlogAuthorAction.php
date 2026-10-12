<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Blog\Domain\Models\BlogAuthor;

/**
 * Delete an author profile only when it is credited on no post (soft-deleted
 * posts included). Credited authors are part of the editorial record — they are
 * deactivated instead, which hides them publicly without rewriting bylines.
 */
final class DeleteBlogAuthorAction
{
    public function handle(BlogAuthor $author): void
    {
        DB::transaction(function () use ($author): void {
            if (DB::table('blog_post_author')->where('blog_author_id', $author->id)->exists()) {
                throw ValidationException::withMessages([
                    'author' => 'This author is credited on posts. Deactivate the profile instead.',
                ]);
            }

            DB::table('blog_subscriptions')->where('subject_type', 'author')->where('subject_id', $author->id)->delete();
            $author->delete();
        });
    }
}
