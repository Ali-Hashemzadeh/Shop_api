<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Services\BlogSlugger;
use Modules\Blog\Infrastructure\Persistence\Repositories\BlogCategoryTree;
use Modules\Media\Domain\Contracts\MediaManagerInterface;

/**
 * Create (POST) or update (PATCH `{category}`) a Blog category
 * (`blog.category.manage`). Rejects self-parenting and cycles.
 */
class SaveBlogCategoryRequest extends ManageBlogCategoriesRequest
{
    private function categoryId(): ?int
    {
        $id = $this->route('category');

        return $id === null ? null : (int) $id;
    }

    public function rules(): array
    {
        $creating = $this->categoryId() === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:'.BlogSlugger::PATTERN, Rule::unique('blog_categories', 'slug')->ignore($this->categoryId())],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('blog_categories', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'media_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $id = $this->categoryId();
            if ($id !== null && $this->filled('parent_id') && app(BlogCategoryTree::class)->wouldCreateCycle($id, (int) $this->input('parent_id'))) {
                $v->errors()->add('parent_id', 'A category cannot be placed under itself or one of its descendants.');
            }

            if ($this->filled('media_id') && app(MediaManagerInterface::class)->getMedia((int) $this->input('media_id')) === null) {
                $v->errors()->add('media_id', 'The selected media does not exist.');
            }
        });
    }
}
