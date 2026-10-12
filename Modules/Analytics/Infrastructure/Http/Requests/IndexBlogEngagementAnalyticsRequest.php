<?php

declare(strict_types=1);

namespace Modules\Analytics\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexBlogEngagementAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('analytics.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'metric' => ['nullable', 'string', Rule::in(['views', 'likes', 'bookmarks', 'comments', 'product_clicks'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function filters(): array
    {
        return array_filter([
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'metric' => $this->validated('metric'),
            'limit' => $this->validated('limit'),
        ], fn ($value) => $value !== null);
    }
}
