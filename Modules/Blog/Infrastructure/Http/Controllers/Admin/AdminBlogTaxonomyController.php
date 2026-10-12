<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Blog\Application\Actions\DeleteBlogAuthorAction;
use Modules\Blog\Application\Actions\DeleteBlogCategoryAction;
use Modules\Blog\Application\Actions\DeleteBlogTagAction;
use Modules\Blog\Application\Actions\SaveBlogAuthorAction;
use Modules\Blog\Application\Actions\SaveBlogCategoryAction;
use Modules\Blog\Application\Actions\SaveBlogTagAction;
use Modules\Blog\Domain\DTOs\BlogTagDTO;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexAdminBlogLookupRequest;
use Modules\Blog\Infrastructure\Http\Requests\ManageBlogAuthorsRequest;
use Modules\Blog\Infrastructure\Http\Requests\ManageBlogCategoriesRequest;
use Modules\Blog\Infrastructure\Http\Requests\ManageBlogTagsRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogAuthorRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogCategoryRequest;
use Modules\Blog\Infrastructure\Http\Requests\SaveBlogTagRequest;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogAuthorResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogCategoryResource;
use Modules\Blog\Infrastructure\Http\Resources\BlogTagResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogLookupQueries;

/** Category / tag / author management (numeric ids). */
class AdminBlogTaxonomyController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogLookupQueries $lookups,
    ) {}

    // ── categories ──────────────────────────────────────────────────────────

    public function categories(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return $this->paginated(BlogCategoryResource::class, $this->lookups->paginateCategoriesAdmin(
            $request->safe()->only(['search', 'is_active']),
            (int) $request->validated('per_page', 15),
        ));
    }

    public function categoryTree(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return response()->json(['data' => BlogCategoryResource::collection($this->lookups->categoryTree(activeOnly: false))]);
    }

    public function storeCategory(SaveBlogCategoryRequest $request, SaveBlogCategoryAction $action): JsonResponse
    {
        $category = $action->handle(null, $request->validated());

        return response()->json(['data' => new BlogCategoryResource($this->lookups->categoryDto($category))], 201);
    }

    public function updateCategory(SaveBlogCategoryRequest $request, int $category, SaveBlogCategoryAction $action): JsonResponse
    {
        $model = $action->handle(BlogCategory::query()->findOrFail($category), $request->validated());

        return response()->json(['data' => new BlogCategoryResource($this->lookups->categoryDto($model))]);
    }

    public function destroyCategory(ManageBlogCategoriesRequest $request, int $category, DeleteBlogCategoryAction $action): Response
    {
        $action->handle(BlogCategory::query()->findOrFail($category));

        return response()->noContent();
    }

    // ── tags ────────────────────────────────────────────────────────────────

    public function tags(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return $this->paginated(BlogTagResource::class, $this->lookups->paginateTags(
            $request->safe()->only(['search', 'is_active']),
            (int) $request->validated('per_page', 15),
            activeOnly: false,
        ));
    }

    public function storeTag(SaveBlogTagRequest $request, SaveBlogTagAction $action): JsonResponse
    {
        return response()->json(['data' => new BlogTagResource(BlogTagDTO::fromModel($action->handle(null, $request->validated())))], 201);
    }

    public function updateTag(SaveBlogTagRequest $request, int $tag, SaveBlogTagAction $action): JsonResponse
    {
        $model = $action->handle(BlogTag::query()->findOrFail($tag), $request->validated());

        return response()->json(['data' => new BlogTagResource(BlogTagDTO::fromModel($model))]);
    }

    public function destroyTag(ManageBlogTagsRequest $request, int $tag, DeleteBlogTagAction $action): Response
    {
        $action->handle(BlogTag::query()->findOrFail($tag));

        return response()->noContent();
    }

    // ── authors ─────────────────────────────────────────────────────────────

    public function authors(IndexAdminBlogLookupRequest $request): JsonResponse
    {
        return $this->paginated(AdminBlogAuthorResource::class, $this->lookups->paginateAuthors(
            $request->safe()->only(['search', 'is_active']),
            (int) $request->validated('per_page', 15),
            activeOnly: false,
        ));
    }

    public function storeAuthor(SaveBlogAuthorRequest $request, SaveBlogAuthorAction $action): JsonResponse
    {
        $author = $action->handle(null, $request->validated());

        return response()->json(['data' => new AdminBlogAuthorResource($this->lookups->authorDto($author))], 201);
    }

    public function updateAuthor(SaveBlogAuthorRequest $request, int $author, SaveBlogAuthorAction $action): JsonResponse
    {
        $model = $action->handle(BlogAuthor::query()->findOrFail($author), $request->validated());

        return response()->json(['data' => new AdminBlogAuthorResource($this->lookups->authorDto($model))]);
    }

    public function destroyAuthor(ManageBlogAuthorsRequest $request, int $author, DeleteBlogAuthorAction $action): Response
    {
        $action->handle(BlogAuthor::query()->findOrFail($author));

        return response()->noContent();
    }
}
