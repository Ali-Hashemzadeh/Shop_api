<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Blog\Domain\Contracts\BlogManagerInterface;
use Modules\Blog\Domain\DTOs\BlogPostSummaryDTO;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Infrastructure\Http\Resources\BlogSitemapEntryResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;
use Modules\Blog\Infrastructure\Support\BlogUrls;

/**
 * RSS 2.0 feed and sitemap data — published posts only, built from the same
 * publiclyVisible() scope as every other public read, so a draft, scheduled,
 * archived, expired or deleted post can never appear.
 */
class BlogFeedController extends Controller
{
    public function __construct(
        private readonly BlogPostHydrator $hydrator,
        private readonly BlogManagerInterface $blog,
    ) {}

    public function feed(): Response
    {
        $posts = BlogPost::query()->publiclyVisible()->select(BlogPost::listColumns())
            ->with(BlogPostHydrator::SUMMARY_RELATIONS)
            ->orderByDesc('published_at')->orderByDesc('id')
            ->limit(max(1, (int) config('blog.feed.limit', 20)))
            ->get();

        $summaries = $this->hydrator->summaries($posts, null);
        $lastBuild = $summaries === [] ? now() : max(array_map(fn (BlogPostSummaryDTO $s) => $s->updatedAt, $summaries));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<channel>'
            .'<title>'.$this->e((string) config('blog.feed.title')).'</title>'
            .'<link>'.$this->e(BlogUrls::blogHome()).'</link>'
            .'<description>'.$this->e((string) config('blog.feed.description')).'</description>'
            .'<language>'.$this->e((string) config('blog.feed.language', 'fa-IR')).'</language>'
            .'<lastBuildDate>'.$lastBuild->toRssString().'</lastBuildDate>'
            .'<atom:link href="'.$this->e(url('/api/v1/blog/feed')).'" rel="self" type="application/rss+xml"/>';

        foreach ($summaries as $post) {
            $link = BlogUrls::post($post->slug);
            $xml .= '<item>'
                .'<title>'.$this->e($post->title).'</title>'
                .'<link>'.$this->e($link).'</link>'
                .'<guid isPermaLink="false">'.$this->e($post->publicCode).'</guid>'
                .'<description>'.$this->e((string) $post->excerpt).'</description>'
                .($post->authors !== [] ? '<dc:creator>'.$this->e(implode(', ', array_map(fn ($a) => $a->displayName, $post->authors))).'</dc:creator>' : '')
                .($post->publishedAt !== null ? '<pubDate>'.$post->publishedAt->toRssString().'</pubDate>' : '')
                .'<atom:updated>'.$post->updatedAt->toAtomString().'</atom:updated>';

            foreach ($post->categories as $category) {
                $xml .= '<category>'.$this->e($category->name).'</category>';
            }

            if ($post->featuredMedia !== null) {
                $xml .= '<enclosure url="'.$this->e($post->featuredMedia->url).'" type="'.$this->e($post->featuredMedia->mimeType ?? 'image/jpeg').'" length="0"/>';
            }

            $xml .= '</item>';
        }

        $xml .= '</channel></rss>';

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /** Indexable Blog URLs for the application's sitemap layer. */
    public function sitemap(): JsonResponse
    {
        return response()->json(['data' => BlogSitemapEntryResource::collection($this->blog->getSitemapEntries())]);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
