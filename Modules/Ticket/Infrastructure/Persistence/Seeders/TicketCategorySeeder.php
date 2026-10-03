<?php

namespace Modules\Ticket\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ticket\Domain\Models\TicketCategory;

/**
 * A sensible default set of ticket categories. Idempotent (firstOrCreate by
 * code), so reseeding never duplicates or overwrites operator-edited names.
 */
class TicketCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['code' => 'general', 'name' => 'سوال عمومی', 'sort_order' => 1],
            ['code' => 'order', 'name' => 'سفارش', 'sort_order' => 2],
            ['code' => 'payment', 'name' => 'پرداخت', 'sort_order' => 3],
            ['code' => 'shipment', 'name' => 'ارسال و تحویل', 'sort_order' => 4],
            ['code' => 'product', 'name' => 'محصول', 'sort_order' => 5],
            ['code' => 'technical', 'name' => 'مشکل فنی', 'sort_order' => 6],
        ];

        foreach ($categories as $category) {
            TicketCategory::firstOrCreate(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                ],
            );
        }
    }
}
