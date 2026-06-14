<?php

namespace Modules\Media\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('media.upload');
    }

    public function rules(): array
    {
        return [
            'file'   => ['required', 'file', 'image', 'max:4096'],
            'folder' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-\/]+$/'],
        ];
    }
}
