<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostRevision;

/**
 * Mint a signed, time-limited preview URL for ONE post (optionally pinned to
 * one of its revisions). The URL carries an HMAC signature over the post id,
 * revision id, and expiry; tampering with any of them, or using it after
 * `expires_at`, is rejected by the `signed` middleware. There is no
 * `?preview=true` back door.
 *
 * @return array{url: string, expires_at: CarbonInterface}
 */
final class CreateBlogPreviewLinkAction
{
    /** @return array{url: string, expires_at: CarbonInterface} */
    public function handle(BlogPost $post, ?BlogPostRevision $revision = null): array
    {
        $expiresAt = now()->addMinutes(max(1, (int) config('blog.preview.ttl_minutes', 60)));

        $parameters = ['post' => $post->id];
        if ($revision !== null) {
            $parameters['revision'] = $revision->id;
        }

        return [
            'url' => URL::temporarySignedRoute('blog.preview', $expiresAt, $parameters),
            'expires_at' => $expiresAt,
        ];
    }
}
