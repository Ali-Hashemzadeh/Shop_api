<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Enums\RedemptionStatus;

/**
 * Shared read guard for every admin promotion listing and detail route.
 *
 * Authorization lives here rather than in a policy so an unauthorized caller gets
 * 403 *before* validation runs, matching the repository's convention and avoiding
 * a 422 bleed-through that would reveal which filters exist.
 */
class IndexPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('promotion.view-admin');
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'show_on_landing' => ['nullable', 'boolean'],
            'trigger_type' => ['nullable', 'string', 'in:'.implode(',', array_column(DiscountTriggerType::cases(), 'value'))],
            'discount_id' => ['nullable', 'integer'],
            'coupon_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(RedemptionStatus::cases(), 'value'))],
        ];
    }

    /**
     * Only the keys the caller actually supplied, so an absent filter is never
     * mistaken for an explicit `false`.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = [];

        foreach (['search', 'trigger_type', 'status'] as $key) {
            $value = $this->string($key)->trim()->toString();

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        foreach (['discount_id', 'coupon_id', 'order_id', 'user_id'] as $key) {
            if ($this->filled($key)) {
                $filters[$key] = $this->integer($key);
            }
        }

        foreach (['is_active', 'show_on_landing'] as $key) {
            if ($this->has($key)) {
                $filters[$key] = $this->boolean($key);
            }
        }

        return $filters;
    }

    public function perPage(): int
    {
        return min(max($this->integer('per_page', 15), 1), 100);
    }
}
