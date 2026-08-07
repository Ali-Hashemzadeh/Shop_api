<?php

namespace Modules\Catalog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\Category;

class CategoryTreeSeeder extends Seeder
{
    public function run(): void
    {
        // Root
        $electronics = $this->category('Electronics', 'electronics');

        // Child 1 — has 2 grandchildren
        $phones = $this->category('Phones', 'phones', $electronics->id);
        $this->category('Android', 'android', $phones->id);
        $this->category('iPhone', 'iphone', $phones->id);

        // Child 2 — has 2 grandchildren
        $laptops = $this->category('Laptops', 'laptops', $electronics->id);
        $this->category('Gaming Laptops', 'gaming-laptops', $laptops->id);
        $this->category('Business Laptops', 'business-laptops', $laptops->id);

        // Child 3 — leaf
        $this->category('Tablets', 'tablets', $electronics->id);

        // Child 4 — leaf
        $this->category('Accessories', 'accessories', $electronics->id);

        $this->command->info('Category tree seeded: Electronics → [Phones (Android, iPhone), Laptops (Gaming, Business), Tablets, Accessories]');
    }

    /**
     * Seeders run under WithoutModelEvents, so the `creating` hook that normally
     * assigns `public_code` never fires — and the column is deliberately not
     * mass-assignable. Set it explicitly through the module's own generator.
     */
    private function category(string $name, string $slug, ?int $parentId = null): Category
    {
        $category = new Category([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'parent_id' => $parentId,
        ]);

        $category->public_code = Category::generateUniquePublicCode();
        $category->save();

        return $category;
    }
}
