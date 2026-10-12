<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

/** Add a private editorial note. */
class StoreBlogEditorialNoteRequest extends BlogCollaborationRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
