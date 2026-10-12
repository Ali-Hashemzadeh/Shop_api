<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogPostSort;

/**
 * Public post listing / search. Never authorizes anything (public route) and
 * never accepts a status — public reads are published-only, always.
 */
class IndexPublicBlogPostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'nullable', 'string', 'max:180'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:180'],
            'author' => ['sometimes', 'nullable', 'string', 'max:180'],
            'product_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'featured' => ['sometimes', 'nullable', 'boolean'],
            'published_from' => ['sometimes', 'nullable', 'date'],
            'published_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:published_from'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(BlogPostSort::values())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $data = $this->validated();

        return [
            'search' => $data['search'] ?? null,
            'category' => $data['category'] ?? null,
            'tag' => $data['tag'] ?? null,
            'author' => $data['author'] ?? null,
            'product_id' => $data['product_id'] ?? null,
            'featured' => array_key_exists('featured', $data) && $data['featured'] !== null ? (bool) $data['featured'] : null,
            'published_from' => $data['published_from'] ?? null,
            'published_to' => $data['published_to'] ?? null,
            'sort' => $data['sort'] ?? null,
        ];
    }
}
