<?php

declare(strict_types=1);

namespace Modules\Analytics\Domain\DTOs;

class AnalyticsBlogEngagementDTO
{
    /**
     * @param  list<array{date: string, views: int, likes: int, bookmarks: int, comments: int, product_clicks: int}>  $daily
     * @param  list<array{blog_post_id: int, views: int, likes: int, bookmarks: int, comments: int, product_clicks: int}>  $topPosts
     * @param  array{views: int, likes: int, bookmarks: int, comments: int, product_clicks: int}  $summary
     */
    public function __construct(
        public readonly array $daily,
        public readonly array $topPosts,
        public readonly array $summary,
    ) {}

    public function toArray(): array
    {
        return [
            'daily' => $this->daily,
            'top_posts' => $this->topPosts,
            'summary' => $this->summary,
        ];
    }
}
