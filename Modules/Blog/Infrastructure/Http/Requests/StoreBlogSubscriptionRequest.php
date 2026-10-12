<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Blog\Domain\Enums\BlogSubscriptionSubject;
use Modules\Blog\Domain\Models\BlogAuthor;
use Modules\Blog\Domain\Models\BlogCategory;
use Modules\Blog\Domain\Models\BlogTag;

/**
 * Follow a Blog subject (self-service). The subject type is an enum whitelist;
 * the subject must exist and be active — checked against Blog's own tables,
 * since categories, tags, and authors are Blog-owned.
 */
class StoreBlogSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', Rule::in(BlogSubscriptionSubject::values())],
            'subject_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $model = match (BlogSubscriptionSubject::from((string) $this->input('subject_type'))) {
                BlogSubscriptionSubject::Category => BlogCategory::class,
                BlogSubscriptionSubject::Tag => BlogTag::class,
                BlogSubscriptionSubject::Author => BlogAuthor::class,
            };

            if (! $model::query()->whereKey((int) $this->input('subject_id'))->where('is_active', true)->exists()) {
                $v->errors()->add('subject_id', 'The selected subject does not exist.');
            }
        });
    }
}
