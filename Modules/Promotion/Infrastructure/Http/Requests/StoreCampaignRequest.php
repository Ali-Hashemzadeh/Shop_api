<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.campaign.manage');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The slug is the public handle for GET /catalog/campaigns/{slug}/products.
            'slug' => ['required', 'string', 'max:255', 'unique:campaigns,slug'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'show_on_landing' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'discount_ids' => ['nullable', 'array'],
            // Membership is restricted to automatic rules; SaveCampaignAction rejects
            // coupon-backed ones, which have no product identity to merchandise.
            'discount_ids.*' => ['integer', 'exists:discounts,id'],
        ];
    }
}
