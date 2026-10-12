<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogCommentReportReason;

/** Report a visible comment. Any signed-in reader may report (self-service). */
class ReportBlogCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(BlogCommentReportReason::values())],
            'details' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
