<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Persistence\Repositories;

use App\Support\PublicCodeGenerator;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Blog\Domain\DTOs\BlogCommentDTO;
use Modules\Blog\Domain\DTOs\BlogCommentThreadDTO;
use Modules\Blog\Domain\DTOs\BlogMediaDTO;
use Modules\Blog\Domain\Enums\BlogCommentReaction;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Identity\Domain\Contracts\IdentityManagerInterface;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Comment reads with batched enrichment (attachments, reactions, author names):
 * a fixed number of queries per page regardless of how many comments it holds.
 *
 * Public threads contain approved comments only. A soft-deleted (approved)
 * comment survives as a body-less placeholder exactly when something visible
 * hangs below it, so a reply never loses the comment it answered.
 */
final class BlogCommentQueries
{
    public function __construct(
        private readonly IdentityManagerInterface $identity,
        private readonly MediaManagerInterface $media,
    ) {}

    public function paginatePublicThreads(BlogPost $post, int $perPage, string $sort, ?int $viewerId): LengthAwarePaginator
    {
        // Lightweight skeleton of every approved comment on the post.
        $skeleton = BlogComment::withTrashed()
            ->where('blog_post_id', $post->id)
            ->where('status', BlogCommentStatus::Approved->value)
            ->get(['id', 'parent_id', 'deleted_at']);

        $children = [];
        $deleted = [];
        foreach ($skeleton as $row) {
            $children[(int) ($row->parent_id ?? 0)][] = (int) $row->id;
            $deleted[(int) $row->id] = $row->deleted_at !== null;
        }

        // A node is shown if it is live or has a shown descendant.
        $shown = [];
        $resolve = function (int $id) use (&$resolve, &$shown, $children, $deleted): bool {
            if (isset($shown[$id])) {
                return $shown[$id];
            }
            $keep = ! ($deleted[$id] ?? true);
            foreach ($children[$id] ?? [] as $childId) {
                $keep = $resolve($childId) || $keep;
            }

            return $shown[$id] = $keep;
        };

        $rootIds = array_values(array_filter($children[0] ?? [], fn (int $id) => $resolve($id)));

        $paginator = BlogComment::withTrashed()
            ->whereIn('id', $rootIds === [] ? [0] : $rootIds)
            ->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')
            ->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $pageRoots = $paginator->getCollection();

        $descendantIds = [];
        $stack = $pageRoots->pluck('id')->map(fn ($id) => (int) $id)->all();
        while ($stack !== []) {
            $id = array_pop($stack);
            foreach ($children[$id] ?? [] as $childId) {
                if ($shown[$childId] ?? false) {
                    $descendantIds[] = $childId;
                    $stack[] = $childId;
                }
            }
        }

        $replies = $descendantIds === []
            ? collect()
            : BlogComment::withTrashed()->whereIn('id', $descendantIds)->orderBy('created_at')->orderBy('id')->get();

        $dtos = $this->toDtos($pageRoots->concat($replies), $viewerId, $post->public_code);
        $byParent = [];
        foreach ($replies as $reply) {
            $byParent[(int) $reply->parent_id][] = (int) $reply->id;
        }

        $build = function (int $id) use (&$build, $dtos, $byParent): BlogCommentThreadDTO {
            return new BlogCommentThreadDTO(
                comment: $dtos[$id],
                replies: array_map(fn (int $childId) => $build($childId), $byParent[$id] ?? []),
            );
        };

        return $paginator->setCollection($pageRoots->map(fn (BlogComment $root) => $build((int) $root->id)));
    }

    /**
     * Supported filters: status, post_id, user_id, reported (bool — has open
     * reports), date_from, date_to, trashed (with|only), sort (newest|oldest|most_reported).
     */
    public function paginateAdmin(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = BlogComment::query()->withCount(['reports as open_reports_count' => fn (Builder $q) => $q->where('status', BlogCommentReportStatus::Open->value)]);

        match ($filters['trashed'] ?? null) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        if (filled($filters['status'] ?? null)) {
            $query->where('status', (string) $filters['status']);
        }
        if (filled($filters['post_id'] ?? null)) {
            $query->where('blog_post_id', (int) $filters['post_id']);
        }
        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (array_key_exists('reported', $filters) && $filters['reported'] !== null) {
            $filters['reported']
                ? $query->whereHas('reports', fn (Builder $q) => $q->where('status', BlogCommentReportStatus::Open->value))
                : $query->whereDoesntHave('reports', fn (Builder $q) => $q->where('status', BlogCommentReportStatus::Open->value));
        }
        if (filled($filters['date_from'] ?? null)) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }
        if (filled($filters['date_to'] ?? null)) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'most_reported' => $query->orderByDesc('open_reports_count')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate($perPage)->withQueryString();
        $dtos = $this->toDtos($paginator->getCollection(), null, null, admin: true);

        return $paginator->setCollection($paginator->getCollection()->map(fn (BlogComment $c) => $dtos[(int) $c->id]));
    }

    /** A comment that is publicly visible (approved, live, on a public post). */
    public function findPublic(string $publicCode): ?BlogComment
    {
        return BlogComment::query()
            ->where('public_code', PublicCodeGenerator::normalize($publicCode))
            ->where('status', BlogCommentStatus::Approved->value)
            ->whereHas('post', fn (Builder $q) => $q->publiclyVisible())
            ->first();
    }

    public function single(BlogComment $comment, ?int $viewerId, bool $admin = false): BlogCommentDTO
    {
        return $this->toDtos(collect([$comment]), $viewerId, null, $admin)[(int) $comment->id];
    }

    /**
     * @param  Collection<int, BlogComment>  $comments
     * @return array<int, BlogCommentDTO>
     */
    private function toDtos(Collection $comments, ?int $viewerId, ?string $postPublicCode, bool $admin = false): array
    {
        if ($comments->isEmpty()) {
            return [];
        }

        $ids = $comments->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $mediaRows = DB::table('blog_comment_media')->whereIn('blog_comment_id', $ids)->orderBy('sort_order')->get();
        $mediaMap = $mediaRows->isEmpty()
            ? collect()
            : $this->media->getMediaCollection($mediaRows->pluck('media_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->keyBy('id');

        $reactionCounts = DB::table('blog_comment_reactions')
            ->whereIn('comment_id', $ids)
            ->select('comment_id', 'reaction', DB::raw('count(*) as total'))
            ->groupBy('comment_id', 'reaction')
            ->get();

        $mine = $viewerId === null ? collect() : DB::table('blog_comment_reactions')
            ->where('user_id', $viewerId)
            ->whereIn('comment_id', $ids)
            ->pluck('reaction', 'comment_id');

        $authors = $this->identity->getUserSummaries($comments->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $parentCodes = BlogComment::withTrashed()
            ->whereIn('id', $comments->pluck('parent_id')->filter()->unique()->values()->all() ?: [0])
            ->pluck('public_code', 'id');

        $postCodes = $postPublicCode !== null ? collect() : BlogPost::withTrashed()
            ->whereIn('id', $comments->pluck('blog_post_id')->unique()->values()->all())
            ->pluck('public_code', 'id');

        $result = [];
        foreach ($comments as $comment) {
            $id = (int) $comment->id;
            $isDeleted = $comment->deleted_at !== null;
            $hidden = $isDeleted && ! $admin;

            $counts = array_fill_keys(BlogCommentReaction::values(), 0);
            foreach ($reactionCounts->where('comment_id', $id) as $row) {
                $counts[(string) $row->reaction] = (int) $row->total;
            }

            $attachments = $hidden ? [] : $mediaRows
                ->where('blog_comment_id', $id)
                ->map(fn ($row) => $mediaMap->has((int) $row->media_id)
                    ? BlogMediaDTO::fromMedia($mediaMap->get((int) $row->media_id), sortOrder: (int) $row->sort_order)
                    : null)
                ->filter()
                ->values()
                ->all();

            $author = $authors[(int) $comment->user_id] ?? null;
            $authorName = $author === null ? null : trim(($author->name ?? '').' '.($author->lastName ?? ''));

            $result[$id] = new BlogCommentDTO(
                id: $id,
                publicCode: (string) $comment->public_code,
                postId: (int) $comment->blog_post_id,
                postPublicCode: $postPublicCode ?? ($postCodes[(int) $comment->blog_post_id] ?? null),
                userId: (int) $comment->user_id,
                authorName: $hidden ? null : ($authorName !== '' ? $authorName : null),
                parentId: $comment->parent_id !== null ? (int) $comment->parent_id : null,
                parentPublicCode: $comment->parent_id !== null ? ($parentCodes[(int) $comment->parent_id] ?? null) : null,
                body: $hidden ? null : (string) $comment->body,
                status: $comment->status->value,
                isDeleted: $isDeleted,
                editedAt: $comment->edited_at,
                approvedAt: $comment->approved_at,
                createdAt: Carbon::parse($comment->created_at),
                attachments: $attachments,
                reactionCounts: $counts,
                myReaction: isset($mine[$id]) ? (string) $mine[$id] : null,
                openReportsCount: (int) ($comment->open_reports_count ?? 0),
                isMine: $viewerId !== null && (int) $comment->user_id === $viewerId,
            );
        }

        return $result;
    }
}
