<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Blog\Application\Actions\CreateBlogPostAction;
use Modules\Blog\Application\Actions\DeleteBlogPostAction;
use Modules\Blog\Application\Actions\RestoreBlogPostAction;
use Modules\Blog\Application\Actions\UpdateBlogPostAction;
use Modules\Blog\Domain\DTOs\BlogActivityDTO;
use Modules\Blog\Domain\Models\BlogPost;
use Modules\Blog\Domain\Models\BlogPostActivity;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\DeleteBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\IndexAdminBlogPostsRequest;
use Modules\Blog\Infrastructure\Http\Requests\RestoreBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\StoreBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\UpdateBlogPostRequest;
use Modules\Blog\Infrastructure\Http\Requests\ViewBlogAdminRequest;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogPostResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogActivityResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostHydrator;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogPostQueries;

/** Post CRUD for editors. Admin routes address posts by numeric id. */
class AdminBlogPostController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogPostQueries $posts,
        private readonly BlogPostHydrator $hydrator,
    ) {}

    public function index(IndexAdminBlogPostsRequest $request): JsonResponse
    {
        return $this->paginated(AdminBlogPostResource::class, $this->posts->paginateAdmin(
            $request->safe()->except(['per_page', 'page']),
            (int) $request->validated('per_page', 15),
        ));
    }

    public function store(StoreBlogPostRequest $request, CreateBlogPostAction $action): JsonResponse
    {
        $post = $action->handle($request->validated(), (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($post))], 201);
    }

    public function show(ViewBlogAdminRequest $request, int $post): JsonResponse
    {
        $model = BlogPost::withTrashed()->findOrFail($post);

        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($model))]);
    }

    public function update(UpdateBlogPostRequest $request, UpdateBlogPostAction $action): JsonResponse
    {
        $data = $request->safe()->except(['version']);

        $post = $action->handle($request->blogPost(), $data, (int) $request->validated('version'), (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($post))]);
    }

    public function destroy(DeleteBlogPostRequest $request, DeleteBlogPostAction $action): Response
    {
        $action->handle($request->blogPost(), (int) $request->user()->getAuthIdentifier());

        return response()->noContent();
    }

    public function restore(RestoreBlogPostRequest $request, RestoreBlogPostAction $action): JsonResponse
    {
        $post = $action->handle($request->blogPost(), (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new AdminBlogPostResource($this->hydrator->adminDetail($post))]);
    }

    /** The editorial audit trail, newest first. */
    public function activity(ViewBlogAdminRequest $request, int $post): JsonResponse
    {
        $model = BlogPost::withTrashed()->findOrFail($post);

        $paginator = BlogPostActivity::query()
            ->where('blog_post_id', $model->id)
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 15))))
            ->withQueryString();

        $paginator->setCollection($paginator->getCollection()->map(fn (BlogPostActivity $a) => BlogActivityDTO::fromModel($a)));

        return $this->paginated(BlogActivityResource::class, $paginator);
    }
}
