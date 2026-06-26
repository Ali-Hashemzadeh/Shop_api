<?php

namespace Modules\Catalog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\Category;

class CategoryTreeSeeder extends Seeder
{
    public function run(): void
    {
        // Root
        $electronics = Category::create([
            'name'      => 'Electronics',
            'slug'      => 'electronics',
            'is_active' => true,
        ]);

        // Child 1 — has 2 grandchildren
        $phones = Category::create([
            'name'      => 'Phones',
            'slug'      => 'phones',
            'is_active' => true,
            'parent_id' => $electronics->id,
        ]);

        Category::create([
            'name'      => 'Android',
            'slug'      => 'android',
            'is_active' => true,
            'parent_id' => $phones->id,
        ]);

        Category::create([
            'name'      => 'iPhone',
            'slug'      => 'iphone',
            'is_active' => true,
            'parent_id' => $phones->id,
        ]);

        // Child 2 — has 2 grandchildren
        $laptops = Category::create([
            'name'      => 'Laptops',
            'slug'      => 'laptops',
            'is_active' => true,
            'parent_id' => $electronics->id,
        ]);

        Category::create([
            'name'      => 'Gaming Laptops',
            'slug'      => 'gaming-laptops',
            'is_active' => true,
            'parent_id' => $laptops->id,
        ]);

        Category::create([
            'name'      => 'Business Laptops',
            'slug'      => 'business-laptops',
            'is_active' => true,
            'parent_id' => $laptops->id,
        ]);

        // Child 3 — leaf
        Category::create([
            'name'      => 'Tablets',
            'slug'      => 'tablets',
            'is_active' => true,
            'parent_id' => $electronics->id,
        ]);

        // Child 4 — leaf
        Category::create([
            'name'      => 'Accessories',
            'slug'      => 'accessories',
            'is_active' => true,
            'parent_id' => $electronics->id,
        ]);

        $this->command->info('Category tree seeded: Electronics → [Phones (Android, iPhone), Laptops (Gaming, Business), Tablets, Accessories]');
    }
}
