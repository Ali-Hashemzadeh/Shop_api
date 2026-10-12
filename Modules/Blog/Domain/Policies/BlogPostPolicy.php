<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Blog\Domain\Enums\BlogPostStatus;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogPost;

/**
 * Post authorization — permission-based, never role names, typehinted against
 * framework contracts (never Identity's User model). Future bundles such as
 * `blog_author`, `blog_editor`, `blog_moderator` are just roles holding these
 * permissions; no business code changes.
 *
 * Ownership ("own" post) = the caller created it, or is the account linked to
 * one of its credited Blog author profiles.
 */
class BlogPostPolicy
{
    public function update(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        if ($user->can('blog.post.update-any')) {
            return true;
        }

        return $user->can('blog.post.update-own')
            && $post->status === BlogPostStatus::Draft
            && $this->owns($user, $post);
    }

    public function submitReview(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.submit-review')
            && ($user->can('blog.post.update-any') || $this->owns($user, $post));
    }

    public function approve(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.approve');
    }

    public function returnToDraft(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.approve');
    }

    public function publish(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.publish');
    }

    public function archive(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.archive');
    }

    public function delete(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.delete');
    }

    public function restore(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.restore');
    }

    /** Editorial notes, autosave recovery: people working on this article. */
    public function collaborate(Authorizable&Authenticatable $user, BlogPost $post): bool
    {
        return $user->can('blog.post.update-any')
            || $user->can('blog.post.approve')
            || $user->can('blog.post.publish')
            || ($user->can('blog.post.update-own') && $this->owns($user, $post));
    }

    private function owns(Authenticatable $user, BlogPost $post): bool
    {
        $userId = (int) $user->getAuthIdentifier();

        if ((int) $post->created_by_user_id === $userId) {
            return true;
        }

        return $post->authors()->where('blog_authors.user_id', $userId)->exists()
            || BlogAuthor::query()->whereKey($post->primary_author_id)->where('user_id', $userId)->exists();
    }
}
