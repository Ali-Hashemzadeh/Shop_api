<?php

declare(strict_types=1);

namespace Modules\Blog\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Services\BlogSlugger;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogCategoryTree;

/**
 * Create or update a Blog category. The hierarchy invariant (no self-parent, no
 * cycle) is re-checked here under the transaction even though the request
 * already validated it, because the tree may have changed in between.
 */
final class SaveBlogCategoryAction
{
    private const FIELDS = ['parent_id', 'name', 'slug', 'description', 'media_id', 'is_active', 'sort_order', 'seo_title', 'seo_description'];

    public function __construct(
        private readonly BlogCategoryTree $tree,
    ) {}

    public function handle(?BlogCategory $category, array $data): BlogCategory
    {
        return DB::transaction(function () use ($category, $data): BlogCategory {
            $category ??= new BlogCategory;

            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $category->setAttribute($field, $data[$field]);
                }
            }

            if (blank($category->slug)) {
                $category->slug = BlogSlugger::unique(
                    BlogSlugger::slugify((string) $category->name),
                    fn (string $candidate) => BlogCategory::query()->where('slug', $candidate)->exists(),
                );
            }

            if ($category->exists && $category->isDirty('parent_id')) {
                $this->tree->forget();
                $parentId = $category->parent_id !== null ? (int) $category->parent_id : null;
                if ($this->tree->wouldCreateCycle((int) $category->id, $parentId)) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'A category cannot be placed under itself or one of its descendants.',
                    ]);
                }
            }

            $category->save();
            $this->tree->forget();

            return $category->fresh();
        });
    }
}
