<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Blog\Infrastructure\Http\Requests\ViewBlogAnalyticsRequest;

/**
 * Blog engagement report (`blog.analytics.view`), computed from Blog's own
 * maintained counters (`blog_post_stats`) joined only with Blog's own tables —
 * never Analytics' tables. Time-series reporting lives in the Analytics module,
 * which consumes Blog's integration events into its own read model.
 */
class AdminBlogAnalyticsController extends Controller
{
    private const METRICS = ['views_count', 'likes_count', 'bookmarks_count', 'comments_count', 'product_clicks_count'];

    public function __invoke(ViewBlogAnalyticsRequest $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => ['sometimes', 'string', Rule::in(self::METRICS)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $metric = $validated['metric'] ?? 'views_count';
        $limit = (int) ($validated['limit'] ?? 10);

        $totals = DB::table('blog_post_stats')
            ->join('blog_posts', 'blog_posts.id', '=', 'blog_post_stats.blog_post_id')
            ->whereNull('blog_posts.deleted_at')
            ->selectRaw(implode(', ', array_map(fn ($m) => "coalesce(sum(blog_post_stats.{$m}), 0) as {$m}", self::METRICS)))
            ->first();

        $topPosts = DB::table('blog_post_stats')
            ->join('blog_posts', 'blog_posts.id', '=', 'blog_post_stats.blog_post_id')
            ->whereNull('blog_posts.deleted_at')
            ->orderByDesc("blog_post_stats.{$metric}")
            ->orderByDesc('blog_posts.id')
            ->limit($limit)
            ->get(['blog_posts.id', 'blog_posts.public_code', 'blog_posts.title', 'blog_posts.slug', 'blog_posts.status', ...array_map(fn ($m) => "blog_post_stats.{$m}", self::METRICS)]);

        return response()->json([
            'data' => [
                'metric' => $metric,
                'totals' => collect((array) $totals)->map(fn ($v) => (int) $v)->all(),
                'top_posts' => $topPosts->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'public_code' => $row->public_code,
                    'title' => $row->title,
                    'slug' => $row->slug,
                    'status' => $row->status,
                    ...collect(self::METRICS)->mapWithKeys(fn ($m) => [$m => (int) $row->{$m}])->all(),
                ])->all(),
                'top_categories' => $this->topBy('blog_post_category', 'blog_category_id', 'blog_categories', 'name', $metric, $limit),
                'top_tags' => $this->topBy('blog_post_tag', 'blog_tag_id', 'blog_tags', 'name', $metric, $limit),
                'top_authors' => $this->topBy('blog_post_author', 'blog_author_id', 'blog_authors', 'display_name', $metric, $limit),
            ],
        ]);
    }

    /** @return list<array{id: int, name: string, slug: string, total: int, posts: int}> */
    private function topBy(string $pivot, string $foreignKey, string $table, string $nameColumn, string $metric, int $limit): array
    {
        return DB::table($pivot)
            ->join('blog_post_stats', 'blog_post_stats.blog_post_id', '=', "{$pivot}.blog_post_id")
            ->join('blog_posts', 'blog_posts.id', '=', "{$pivot}.blog_post_id")
            ->join($table, "{$table}.id", '=', "{$pivot}.{$foreignKey}")
            ->whereNull('blog_posts.deleted_at')
            ->groupBy("{$table}.id", "{$table}.{$nameColumn}", "{$table}.slug")
            ->selectRaw("{$table}.id as id, {$table}.{$nameColumn} as name, {$table}.slug as slug, sum(blog_post_stats.{$metric}) as total, count(*) as posts")
            ->orderByDesc('total')
            ->orderBy("{$table}.id")
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'total' => (int) $row->total,
                'posts' => (int) $row->posts,
            ])
            ->all();
    }
}
