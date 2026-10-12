<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Modules\Blog\Application\Actions\SubscribeToBlogSubjectAction;
use Modules\Blog\Application\Actions\UnsubscribeFromBlogSubjectAction;
use Modules\Blog\Domain\DTOs\BlogSubscriptionDTO;
use Modules\Blog\Domain\Enums\BlogSubscriptionSubject;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogSubscription;
use Modules\Blog\Domain\Models\BlogTag;
use Modules\Blog\Infrastructure\Http\Controllers\Concerns\InteractsWithBlogViewer;
use Modules\Blog\Infrastructure\Http\Requests\IndexPublicBlogListRequest;
use Modules\Blog\Infrastructure\Http\Requests\StoreBlogSubscriptionRequest;
use Modules\Blog\Infrastructure\Http\Resources\BlogSubscriptionResource;

/**
 * Follow categories / tags / authors. Caller-scoped structurally: another
 * reader's subscription id is a 404, never a 403.
 */
class BlogSubscriptionController extends Controller
{
    use InteractsWithBlogViewer;

    public function index(IndexPublicBlogListRequest $request): JsonResponse
    {
        $paginator = BlogSubscription::query()
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->orderByDesc('id')
            ->paginate((int) $request->validated('per_page', 15))
            ->withQueryString();

        $paginator->setCollection($this->toDtos($paginator->getCollection()));

        return $this->paginated(BlogSubscriptionResource::class, $paginator);
    }

    public function store(StoreBlogSubscriptionRequest $request, SubscribeToBlogSubjectAction $action): JsonResponse
    {
        $result = $action->handle(
            (int) $request->user()->getAuthIdentifier(),
            BlogSubscriptionSubject::from((string) $request->validated('subject_type')),
            (int) $request->validated('subject_id'),
        );

        return response()->json(
            ['data' => new BlogSubscriptionResource($this->toDtos(collect([$result['subscription']]))->first())],
            $result['created'] ? 201 : 200,
        );
    }

    public function destroy(Request $request, int $subscription, UnsubscribeFromBlogSubjectAction $action): Response
    {
        $row = BlogSubscription::query()
            ->where('user_id', (int) $request->user()->getAuthIdentifier())
            ->findOrFail($subscription);

        $action->handle($row);

        return response()->noContent();
    }

    /**
     * @param  Collection<int, BlogSubscription>  $rows
     * @return Collection<int, BlogSubscriptionDTO>
     */
    private function toDtos(Collection $rows): Collection
    {
        $names = [];
        foreach ([
            BlogSubscriptionSubject::Category->value => [BlogCategory::class, 'name'],
            BlogSubscriptionSubject::Tag->value => [BlogTag::class, 'name'],
            BlogSubscriptionSubject::Author->value => [BlogAuthor::class, 'display_name'],
        ] as $type => [$model, $column]) {
            $ids = $rows->filter(fn (BlogSubscription $s) => $s->subject_type->value === $type)->pluck('subject_id')->all();
            $names[$type] = $ids === [] ? collect() : $model::query()->whereIn('id', $ids)->get(['id', $column, 'slug'])->keyBy('id');
        }

        return $rows->map(function (BlogSubscription $s) use ($names): BlogSubscriptionDTO {
            $subject = $names[$s->subject_type->value]->get($s->subject_id);

            return new BlogSubscriptionDTO(
                id: (int) $s->id,
                subjectType: $s->subject_type->value,
                subjectId: (int) $s->subject_id,
                subjectName: $subject?->name ?? $subject?->display_name,
                subjectSlug: $subject?->slug,
                createdAt: Carbon::parse($s->created_at),
            );
        });
    }
}
