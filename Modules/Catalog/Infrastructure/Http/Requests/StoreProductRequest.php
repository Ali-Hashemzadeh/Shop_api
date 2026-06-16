<?php

namespace Modules\Catalog\Infrastructure\Http\Requests;

use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Foundation\Http\FormRequest;

#[BodyParameter('primary_image', description: 'Hero/thumbnail image for the product (JPEG/PNG/WebP, max 4 MB). Send as multipart/form-data. Mutually exclusive with primary_media_id.', type: 'string', format: 'binary', required: false, infer: false)]
#[BodyParameter('gallery', description: 'Ordered gallery images. Send each file as a separate multipart field: gallery[0]=@img1.jpg, gallery[1]=@img2.jpg, … (JPEG/PNG/WebP, max 4 MB each). Array index sets the display sort order.', type: 'string', format: 'binary', required: false, infer: false)]
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('catalog.product.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'description' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'features.*.title' => ['required', 'string', 'max:255'],
            'features.*.value' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,published'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'primary_media_id' => ['nullable', 'integer', 'prohibits:primary_image'],
            'primary_image' => ['nullable', 'file', 'image', 'max:4096', 'prohibits:primary_media_id'],
            'gallery_media_ids' => ['nullable', 'array', 'prohibits:gallery'],
            'gallery_media_ids.*' => ['integer'],
            'gallery' => ['nullable', 'array', 'prohibits:gallery_media_ids'],
            'gallery.*' => ['file', 'image', 'max:4096'],
        ];
    }
}
