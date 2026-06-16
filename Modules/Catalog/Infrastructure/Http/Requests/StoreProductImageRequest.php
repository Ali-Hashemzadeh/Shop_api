<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('image', description: 'Gallery image file (JPEG/PNG/WebP, max 4 MB). Mutually exclusive with media_id.', type: 'string', format: 'binary', required: false, infer: false)]
class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.product.update');
    }

    public function rules(): array
    {
        return [
            'media_id' => ['nullable', 'integer', 'prohibits:image'],
            'image' => ['nullable', 'file', 'image', 'max:4096', 'prohibits:media_id'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
