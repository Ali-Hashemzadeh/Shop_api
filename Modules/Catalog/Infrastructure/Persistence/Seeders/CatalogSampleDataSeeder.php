<?php

namespace Modules\Catalog\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Models\Product;
use Modules\Catalog\Domain\Models\ProductVariant;

class CatalogSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        // --- Categories ---
        $electronics = Category::firstOrCreate(
            ['slug' => 'electronics'],
            ['name' => 'Electronics', 'is_active' => true],
        );

        $phones = Category::firstOrCreate(
            ['slug' => 'phones'],
            ['name' => 'Phones', 'is_active' => true, 'parent_id' => $electronics->id],
        );

        $laptops = Category::firstOrCreate(
            ['slug' => 'laptops'],
            ['name' => 'Laptops', 'is_active' => true, 'parent_id' => $electronics->id],
        );

        $accessories = Category::firstOrCreate(
            ['slug' => 'accessories'],
            ['name' => 'Accessories', 'is_active' => true, 'parent_id' => $electronics->id],
        );

        // --- Products & Variants ---
        $this->seedProduct(
            category: $phones,
            title: 'Galaxy S25',
            slug: 'galaxy-s25',
            description: 'Flagship Android phone with top-tier camera.',
            variants: [
                ['price' => 45_000_000, 'compare' => 50_000_000, 'attrs' => ['storage' => '128GB', 'color' => 'black']],
                ['price' => 52_000_000, 'compare' => 58_000_000, 'attrs' => ['storage' => '256GB', 'color' => 'white']],
                ['price' => 62_000_000, 'compare' => 70_000_000, 'attrs' => ['storage' => '512GB', 'color' => 'violet']],
            ],
        );

        $this->seedProduct(
            category: $phones,
            title: 'iPhone 16',
            slug: 'iphone-16',
            description: 'Apple flagship with A18 chip.',
            variants: [
                ['price' => 60_000_000, 'compare' => 65_000_000, 'attrs' => ['storage' => '128GB', 'color' => 'black']],
                ['price' => 70_000_000, 'compare' => 75_000_000, 'attrs' => ['storage' => '256GB', 'color' => 'pink']],
            ],
        );

        $this->seedProduct(
            category: $laptops,
            title: 'MacBook Pro 14',
            slug: 'macbook-pro-14',
            description: 'M4 chip, 14-inch Liquid Retina XDR display.',
            variants: [
                ['price' => 120_000_000, 'compare' => 130_000_000, 'attrs' => ['chip' => 'M4', 'ram' => '16GB', 'storage' => '512GB']],
                ['price' => 170_000_000, 'compare' => 185_000_000, 'attrs' => ['chip' => 'M4 Pro', 'ram' => '24GB', 'storage' => '1TB']],
            ],
        );

        $this->seedProduct(
            category: $accessories,
            title: 'AirPods Pro 2',
            slug: 'airpods-pro-2',
            description: 'Active noise-cancelling wireless earbuds.',
            variants: [
                ['price' => 15_000_000, 'compare' => 17_000_000, 'attrs' => ['color' => 'white']],
            ],
        );

        $this->seedProduct(
            category: $accessories,
            title: 'USB-C Hub 7-in-1',
            slug: 'usb-c-hub-7in1',
            description: 'HDMI, USB-A x3, SD card, PD charging.',
            variants: [
                ['price' => 3_500_000, 'compare' => 4_000_000, 'attrs' => ['color' => 'space-grey']],
                ['price' => 3_500_000, 'compare' => 4_000_000, 'attrs' => ['color' => 'silver']],
            ],
        );

        $this->command->info('Catalog sample data seeded: 5 products across 3 categories.');
    }

    /** @param array<int,array{price:int,compare:int,attrs:array<string,string>}> $variants */
    private function seedProduct(Category $category, string $title, string $slug, string $description, array $variants): void
    {
        // Generate the public code through the same model helper the `creating` hook
        // uses on a real `POST /products` create. Seeders run under WithoutModelEvents,
        // which mutes that hook, so we invoke the generator explicitly here.
        $product = Product::firstOrCreate(
            ['slug' => $slug],
            [
                'uuid' => Product::generateUniqueUuid(),
                'category_id' => $category->id,
                'title' => $title,
                'description' => $description,
                'status' => 'published',
            ],
        );

        if ($product->variants()->count() > 0) {
            return;
        }

        foreach ($variants as $i => $v) {
            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => 'bdp'.$product->id.'-v'.($i + 1),
                'type' => 'color',
                'is_default' => $i === 0,
                'base_price' => $v['price'],
                'compare_at_price' => $v['compare'],
                'attributes' => $v['attrs'],
            ]);
        }
    }
}
