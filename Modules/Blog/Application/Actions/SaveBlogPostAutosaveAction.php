<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Modules\Blog\Domain\Contracts\BlogHtmlSanitizerInterface;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostAutosave;

/**
 * Store the editor's in-progress draft as their single autosave row for this
 * post, replacing the previous one. Never creates an official revision, never
 * bumps the post version, never touches the live post. Content is sanitized
 * like any other stored HTML.
 */
final class SaveBlogPostAutosaveAction
{
    public function __construct(
        private readonly BlogHtmlSanitizerInterface $sanitizer,
    ) {}

    public function handle(BlogPost $post, int $userId, array $data): BlogPostAutosave
    {
        return BlogPostAutosave::query()->updateOrCreate(
            ['blog_post_id' => $post->id, 'user_id' => $userId],
            [
                'title' => $data['title'] ?? null,
                'excerpt' => $data['excerpt'] ?? null,
                'content' => array_key_exists('content', $data) && $data['content'] !== null
                    ? $this->sanitizer->sanitize((string) $data['content'])
                    : null,
                'base_version' => (int) $post->version,
            ],
        );
    }
}
