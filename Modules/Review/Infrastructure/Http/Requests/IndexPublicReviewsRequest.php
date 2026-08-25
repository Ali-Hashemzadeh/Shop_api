<?php

declare(strict_types=1);

namespace Modules\Review\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Review\Domain\Enums\ReviewSubjectType;

/**
 * Public review listing. `status` is deliberately NOT a rule: the public
 * endpoint always serves approved reviews only, whatever a caller passes — the
 * manager never receives a status filter from this route.
 */
class IndexPublicReviewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('subject_id')) {
            $this->merge(['subject_id' => (int) $this->input('subject_id')]);
        }
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'in:'.implode(',', ReviewSubjectType::values())],
            'subject_id' => ['required', 'integer', 'min:1'],
            'sort' => ['nullable', 'string', 'in:newest,highest,lowest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
