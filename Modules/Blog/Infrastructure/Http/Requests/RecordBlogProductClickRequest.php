<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Public product-click tracking payload. The link to the post is checked by the controller. */
class RecordBlogProductClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'placement' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]+$/'],
        ];
    }
}
