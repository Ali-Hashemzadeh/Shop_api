<?php

declare(strict_types=1);

namespace Modules\Blog\Infrastructure\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Create (POST) or update (PATCH `{placement}`) a keyed placement
 * (`blog.placement.manage`). Keys are free-form dotted identifiers
 * (`blog.home.hero`) — a new slot is data, not schema.
 */
class SaveBlogPlacementRequest extends ManageBlogPlacementsRequest
{
    public function rules(): array
    {
        $creating = $this->route('placement') === null;

        return [
            'placement_key' => [$creating ? 'required' : 'sometimes', 'string', 'max:100', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)*$/'],
            'blog_post_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('blog_posts', 'id')->whereNull('deleted_at')],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
