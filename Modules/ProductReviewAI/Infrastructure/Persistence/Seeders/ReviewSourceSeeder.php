<?php

namespace Modules\ProductReviewAI\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\ProductReviewAI\Domain\Models\ReviewSource;

/**
 * Registers the shipped external sources. `driver` is a stable code — the
 * factory maps it to an adapter, so no PHP namespace is stored in the DB.
 */
class ReviewSourceSeeder extends Seeder
{
    public function run(): void
    {
        ReviewSource::query()->updateOrCreate(
            ['code' => 'digikala'],
            [
                'name' => 'Digikala',
                'driver' => 'digikala',
                'config' => null,
                'is_active' => true,
            ],
        );
    }
}
