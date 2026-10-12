<?php

declare(strict_types=1);

namespace Modules\Blog\Domain\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Blog\Domain\Models\BlogComment;

/**
 * Comment authorization. Authors edit and delete their own comments (standard
 * policy 403 for anyone else); moderators act through moderation permissions.
 */
class BlogCommentPolicy
{
    public function update(Authorizable&Authenticatable $user, BlogComment $comment): bool
    {
        return $user->can('blog.comment.create') && $this->owns($user, $comment);
    }

    public function delete(Authorizable&Authenticatable $user, BlogComment $comment): bool
    {
        return $this->owns($user, $comment) || $user->can('blog.comment.delete');
    }

    public function moderate(Authorizable&Authenticatable $user, BlogComment $comment): bool
    {
        return $user->can('blog.comment.moderate');
    }

    public function adminDelete(Authorizable&Authenticatable $user, BlogComment $comment): bool
    {
        return $user->can('blog.comment.delete');
    }

    private function owns(Authenticatable $user, BlogComment $comment): bool
    {
        return (int) $user->getAuthIdentifier() === (int) $comment->user_id;
    }
}
