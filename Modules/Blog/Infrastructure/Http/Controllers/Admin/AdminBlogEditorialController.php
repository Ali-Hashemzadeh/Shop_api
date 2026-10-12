<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Blog\Application\Actions\AddBlogEditorialNoteAction;
use Modules\Blog\Application\Actions\CreateBlogPreviewLinkAction;
use Modules\Blog\Application\Actions\RestoreBlogPostRevisionAction;
use Modules\Blog\Application\Actions\SaveBlogPostAutosaveAction;
use Modules\Blog\Application\Actions\UpdateBlogEditorialNoteAction;
use Modules\Blog\Domain\DTOs\BlogAutosaveDTO;
use Modules\Blog\Domain\DTOs\BlogEditorialNoteDTO;
use Modules\Blog\Domain\DTOs\BlogRevisionDTO;
use Modules\Blog\Domain\Models\BlogEditorialNote;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostAutosave;
use Modules\Blog\Domain\Models\BlogPostRevision;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\BlogCollaborationRequest;
use Modules\Blog\Infrastructure\Http\Requests\CreateBlogPreviewLinkRequest;
use Modules\Blog\Infrastructure\Http\Requests\RestoreBlogRevisionRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogAutosaveRequest;
use Modules\Blog\Infrastructure\Http\Requests\StoreBlogEditorialNoteRequest;
use Modules\Blog\Infrastructure\Http\Requests\UpdateBlogEditorialNoteRequest;
use Modules\Blog\Infrastructure\Http\Requests\ViewBlogAdminRequest;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogPostResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogAutosaveResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogEditorialNoteResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogRevisionResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;

/**
 * Revisions, editorial notes, autosave, and preview links — the editor's
 * back-office tools. None of it is reachable from a public endpoint.
 */
class AdminBlogEditorialController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogPostHydrator $hydrator,
    ) {}

    // ── revisions ───────────────────────────────────────────────────────────

    public function revisions(ViewBlogAdminRequest $request, int $post): JsonResponse
    {
        $model = BlogPost::withTrashed()->findOrFail($post);

        $paginator = BlogPostRevision::query()
            ->where('blog_post_id', $model->id)
            ->orderByDesc('revision_number')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 15))))
            ->withQueryString();

        $paginator->setCollection($paginator->getCollection()->map(fn (BlogPostRevision $r) => BlogRevisionDTO::fromModel($r, withContent: false)));

        return $this->paginated(BlogRevisionResource::class, $paginator);
    }

    public function revision(ViewBlogAdminRequest $request, int $post, int $revision): JsonResponse
    {
        $model = BlogPost::withTrashed()->findOrFail($post);
        $row = BlogPostRevision::query()->where('blog_post_id', $model->id)->findOrFail($revision);

        return response()->json(['data' => new BlogRevisionResource(BlogRevisionDTO::fromModel($row))]);
    }

    public function restoreRevision(RestoreBlogRevisionRequest $request, int $post, int $revision, RestoreBlogPostRevisionAction $action): JsonResponse
    {
        $model = $request->blogPost();
        $row = BlogPostRevision::query()->where('blog_post_id', $model->id)->findOrFail($revision);

        $restored = $action->handle($model, $row, (int) $request->validated('version'), (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($restored))]);
    }

    // ── editorial notes ─────────────────────────────────────────────────────

    public function notes(BlogCollaborationRequest $request): JsonResponse
    {
        $paginator = BlogEditorialNote::query()
            ->where('blog_post_id', $request->blogPost()->id)
            ->orderByRaw('resolved_at is not null')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 15))))
            ->withQueryString();

        $paginator->setCollection($paginator->getCollection()->map(fn (BlogEditorialNote $n) => BlogEditorialNoteDTO::fromModel($n)));

        return $this->paginated(BlogEditorialNoteResource::class, $paginator);
    }

    public function storeNote(StoreBlogEditorialNoteRequest $request, AddBlogEditorialNoteAction $action): JsonResponse
    {
        $note = $action->handle($request->blogPost(), (int) $request->user()->getAuthIdentifier(), (string) $request->validated('body'));

        return response()->json(['data' => new BlogEditorialNoteResource(BlogEditorialNoteDTO::fromModel($note))], 201);
    }

    public function updateNote(UpdateBlogEditorialNoteRequest $request, UpdateBlogEditorialNoteAction $action): JsonResponse
    {
        $note = $action->handle(
            $request->note(),
            $request->has('resolved') ? $request->boolean('resolved') : null,
            $request->has('body') ? (string) $request->validated('body') : null,
        );

        return response()->json(['data' => new BlogEditorialNoteResource(BlogEditorialNoteDTO::fromModel($note))]);
    }

    // ── autosave ────────────────────────────────────────────────────────────

    /** The caller's latest autosave for this post (`data: null` when there is none). */
    public function autosave(BlogCollaborationRequest $request): JsonResponse
    {
        $post = $request->blogPost();
        $autosave = BlogPostAutosave::query()
            ->where('blog_post_id', $post->id)
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->first();

        if ($autosave === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => new BlogAutosaveResource(BlogAutosaveDTO::fromModel($autosave, (int) $post->version))]);
    }

    public function saveAutosave(SaveBlogAutosaveRequest $request, SaveBlogPostAutosaveAction $action): JsonResponse
    {
        $post = $request->blogPost();
        $autosave = $action->handle($post, (int) $request->user()->getAuthIdentifier(), $request->validated());

        return response()->json(['data' => new BlogAutosaveResource(BlogAutosaveDTO::fromModel($autosave, (int) $post->version))]);
    }

    // ── preview ─────────────────────────────────────────────────────────────

    public function previewLink(CreateBlogPreviewLinkRequest $request, CreateBlogPreviewLinkAction $action): JsonResponse
    {
        $post = $request->blogPost();
        $revision = $request->filled('revision_id')
            ? BlogPostRevision::query()->where('blog_post_id', $post->id)->findOrFail((int) $request->validated('revision_id'))
            : null;

        $link = $action->handle($post, $revision);

        return response()->json([
            'data' => [
                'url' => $link['url'],
                'expires_at' => $link['expires_at']->toISOString(),
            ],
        ], 201);
    }
}
