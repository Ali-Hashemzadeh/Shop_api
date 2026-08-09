<?php

declare(strict_types=1);

namespace Modules\Promotion\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Domain\DTOs\CampaignDTO;
use Modules\Promotion\Domain\Enums\DiscountTriggerType;
use Modules\Promotion\Domain\Models\Campaign;
use Modules\Promotion\Domain\Models\Discount;

/**
 * Create or update a merchandising campaign and its discount membership.
 *
 * Campaigns may only link *automatic* discounts. A coupon rule is store-wide and
 * has no product identity at all, so it could never answer "which products belong
 * in this section?" — linking one is rejected rather than silently ignored.
 */
class SaveCampaignAction
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CampaignDTO
    {
        return DB::transaction(function () use ($data): CampaignDTO {
            $campaign = Campaign::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'show_on_landing' => $data['show_on_landing'] ?? false,
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            if (array_key_exists('discount_ids', $data)) {
                $this->syncDiscounts($campaign, $data['discount_ids'] ?? []);
            }

            return $this->promotion->findCampaign($campaign->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $id, array $data): CampaignDTO
    {
        return DB::transaction(function () use ($id, $data): CampaignDTO {
            $campaign = Campaign::query()->findOrFail($id);

            foreach (['name', 'slug', 'description', 'starts_at', 'ends_at', 'is_active', 'show_on_landing', 'sort_order'] as $field) {
                if (array_key_exists($field, $data)) {
                    $campaign->{$field} = $data[$field];
                }
            }

            $campaign->save();

            if (array_key_exists('discount_ids', $data)) {
                $this->syncDiscounts($campaign, $data['discount_ids'] ?? []);
            }

            return $this->promotion->findCampaign($campaign->id);
        });
    }

    public function delete(int $id): void
    {
        Campaign::query()->findOrFail($id)->delete();
    }

    /**
     * @param  array<int, int|string>  $discountIds
     */
    private function syncDiscounts(Campaign $campaign, array $discountIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $discountIds)));

        if ($ids !== []) {
            $nonAutomatic = Discount::query()
                ->whereIn('id', $ids)
                ->where('trigger_type', '!=', DiscountTriggerType::AUTOMATIC->value)
                ->exists();

            if ($nonAutomatic) {
                throw ValidationException::withMessages([
                    'discount_ids' => ['Only automatic discounts can be linked to a campaign.'],
                ]);
            }
        }

        $campaign->discounts()->sync($ids);
    }
}
