<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Modules\Blog\Domain\Models\BlogEditorialNote;

/**
 * Resolve/reopen a note (any collaborator) and/or edit its body (the note's own
 * author only — anyone else editing the text gets 422 on `body`).
 */
class UpdateBlogEditorialNoteRequest extends BlogCollaborationRequest
{
    private ?BlogEditorialNote $resolvedNote = null;

    public function note(): BlogEditorialNote
    {
        return $this->resolvedNote ??= BlogEditorialNote::query()
            ->where('blog_post_id', $this->blogPost()->id)
            ->findOrFail((int) $this->route('note'));
    }

    public function authorize(): bool
    {
        $this->note();

        return parent::authorize();
    }

    public function rules(): array
    {
        return [
            'resolved' => ['sometimes', 'boolean'],
            'body' => ['sometimes', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->has('body') && (int) $this->note()->user_id !== (int) $this->user()->getAuthIdentifier()) {
                $v->errors()->add('body', 'Only the note\'s author can edit its text.');
            }
        });
    }
}
