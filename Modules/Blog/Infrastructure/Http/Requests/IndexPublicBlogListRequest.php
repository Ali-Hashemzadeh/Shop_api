<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pagination (+ optional search / comment sort / related limit) for public
 * and self-service lists. Authorization is the route's job (public routes
 * carry no auth; self-service routes sit behind auth:sanctum).
 */
class IndexPublicBlogListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'sort' => ['sometimes', 'nullable', 'string', Rule::in(['newest', 'oldest'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('blog.related.max_limit', 20)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
