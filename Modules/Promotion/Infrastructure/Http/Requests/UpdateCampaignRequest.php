<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.campaign.manage');
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('campaigns', 'slug')->ignore((int) $this->route('campaign')),
            ],
            'description' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_landing' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'discount_ids' => ['nullable', 'array'],
            'discount_ids.*' => ['integer', 'exists:discounts,id'],
        ];
    }
}
