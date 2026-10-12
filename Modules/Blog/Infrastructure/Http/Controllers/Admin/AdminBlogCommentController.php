<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Blog\Application\Actions\DeleteBlogCommentAction;
use Modules\Blog\Application\Actions\ModerateBlogCommentAction;
use Modules\Blog\Application\Actions\RestoreBlogCommentAction;
use Modules\Blog\Application\Actions\ReviewBlogCommentReportAction;
use Modules\Blog\Domain\DTOs\BlogCommentReportDTO;
use Modules\Blog\Domain\Enums\BlogCommentReportStatus;
use Modules\Blog\Domain\Enums\BlogCommentStatus;
use Modules\Blog\Domain\Models\BlogComment;
use Modules\Blog\Domain\Models\BlogCommentReport;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\ActionBlogCommentReportRequest;
use Modules\Blog\Infrastructure\Http\Requests\DeleteBlogCommentAdminRequest;
use Modules\Blog\Infrastructure\Http\Requests\IndexAdminBlogCommentsRequest;
use Modules\Blog\Infrastructure\Http\Requests\IndexBlogCommentReportsRequest;
use Modules\Blog\Infrastructure\Http\Requests\ModerateBlogCommentsRequest;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogCommentReportResource;
use Modules\Blog\Infrastructure\Http\Resources\AdminBlogCommentResource;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogCommentQueries;

/**
 * Moderation: the comment queue and the report queue. Numeric ids.
 * `blog.comment.view-admin` reads, `blog.comment.moderate` decides,
 * `blog.comment.delete` removes.
 */
class AdminBlogCommentController extends Controller
{
    use InteractsWithBlogViewer;

    public function __construct(
        private readonly BlogCommentQueries $comments,
    ) {}

    public function index(IndexAdminBlogCommentsRequest $request): JsonResponse
    {
        return $this->paginated(AdminBlogCommentResource::class, $this->comments->paginateAdmin(
            $request->safe()->except(['per_page', 'page']),
            (int) $request->validated('per_page', 15),
        ));
    }

    public function show(IndexAdminBlogCommentsRequest $request, int $comment): JsonResponse
    {
        $model = BlogComment::withTrashed()->withCount(['reports as open_reports_count' => fn ($q) => $q->where('status', 'open')])->findOrFail($comment);

        return response()->json(['data' => new AdminBlogCommentResource($this->comments->single($model, null, admin: true))]);
    }

    public function approve(ModerateBlogCommentsRequest $request, int $comment, ModerateBlogCommentAction $action): JsonResponse
    {
        return $this->moderate($request, $comment, BlogCommentStatus::Approved, $action);
    }

    public function reject(ModerateBlogCommentsRequest $request, int $comment, ModerateBlogCommentAction $action): JsonResponse
    {
        return $this->moderate($request, $comment, BlogCommentStatus::Rejected, $action);
    }

    public function spam(ModerateBlogCommentsRequest $request, int $comment, ModerateBlogCommentAction $action): JsonResponse
    {
        return $this->moderate($request, $comment, BlogCommentStatus::Spam, $action);
    }

    public function restore(ModerateBlogCommentsRequest $request, int $comment, RestoreBlogCommentAction $action): JsonResponse
    {
        $model = $action->handle(BlogComment::withTrashed()->findOrFail($comment));

        return response()->json(['data' => new AdminBlogCommentResource($this->comments->single($model, null, admin: true))]);
    }

    public function destroy(DeleteBlogCommentAdminRequest $request, int $comment, DeleteBlogCommentAction $action): Response
    {
        $action->handle(BlogComment::query()->findOrFail($comment));

        return response()->noContent();
    }

    // ── reports ─────────────────────────────────────────────────────────────

    public function reports(IndexBlogCommentReportsRequest $request): JsonResponse
    {
        $query = BlogCommentReport::query()->with('comment');
        foreach (['status' => 'status', 'reason' => 'reason', 'comment_id' => 'comment_id'] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, $request->validated($input));
            }
        }

        $paginator = $query->orderByDesc('id')->paginate((int) $request->validated('per_page', 15))->withQueryString();
        $paginator->setCollection($paginator->getCollection()->map(fn (BlogCommentReport $r) => BlogCommentReportDTO::fromModel($r)));

        return $this->paginated(AdminBlogCommentReportResource::class, $paginator);
    }

    public function reviewReport(ModerateBlogCommentsRequest $request, int $report, ReviewBlogCommentReportAction $action): JsonResponse
    {
        return $this->closeReport($request, $report, BlogCommentReportStatus::Reviewed, $action);
    }

    public function dismissReport(ModerateBlogCommentsRequest $request, int $report, ReviewBlogCommentReportAction $action): JsonResponse
    {
        return $this->closeReport($request, $report, BlogCommentReportStatus::Dismissed, $action);
    }

    public function actionReport(ActionBlogCommentReportRequest $request, int $report, ReviewBlogCommentReportAction $action): JsonResponse
    {
        return $this->closeReport(
            $request,
            $report,
            BlogCommentReportStatus::Actioned,
            $action,
            $request->filled('comment_status') ? BlogCommentStatus::from((string) $request->validated('comment_status')) : null,
        );
    }

    private function moderate(ModerateBlogCommentsRequest $request, int $comment, BlogCommentStatus $status, ModerateBlogCommentAction $action): JsonResponse
    {
        $model = $action->handle(BlogComment::withTrashed()->findOrFail($comment), $status, (int) $request->user()->getAuthIdentifier());

        return response()->json(['data' => new AdminBlogCommentResource($this->comments->single($model, null, admin: true))]);
    }

    private function closeReport(
        ModerateBlogCommentsRequest $request,
        int $report,
        BlogCommentReportStatus $outcome,
        ReviewBlogCommentReportAction $action,
        ?BlogCommentStatus $commentStatus = null,
    ): JsonResponse {
        $model = $action->handle(
            BlogCommentReport::query()->findOrFail($report),
            $outcome,
            (int) $request->user()->getAuthIdentifier(),
            $commentStatus,
        );

        return response()->json(['data' => new AdminBlogCommentReportResource(BlogCommentReportDTO::fromModel($model))]);
    }
}
