<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Models\Category;
use Modules\Catalog\Domain\Services\CategoryHierarchy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private CategoryHierarchy $hierarchy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hierarchy = new CategoryHierarchy;
    }

    #[Test]
    public function it_returns_only_the_category_itself_for_a_leaf_with_no_descendants(): void
    {
        $leaf = Category::create(['name' => 'Standalone', 'slug' => 'standalone', 'is_active' => true]);

        $descendants = $this->hierarchy->descendantsOf([$leaf->id]);

        $this->assertEqualsCanonicalizing([$leaf->id], $descendants);
    }

    #[Test]
    public function it_returns_category_and_all_nested_descendants_recursively(): void
    {
        // Electronics -> Phones -> Android -> Pixel
        $root = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $phones = Category::create(['name' => 'Phones', 'slug' => 'phones', 'parent_id' => $root->id, 'is_active' => true]);
        $android = Category::create(['name' => 'Android', 'slug' => 'android', 'parent_id' => $phones->id, 'is_active' => true]);
        $pixel = Category::create(['name' => 'Pixel', 'slug' => 'pixel', 'parent_id' => $android->id, 'is_active' => true]);
        $galaxy = Category::create(['name' => 'Galaxy', 'slug' => 'galaxy', 'parent_id' => $android->id, 'is_active' => true]);
        $iphone = Category::create(['name' => 'iPhone', 'slug' => 'iphone', 'parent_id' => $phones->id, 'is_active' => true]);

        // Root expands to all 6 categories
        $rootDescendants = $this->hierarchy->descendantsOf([$root->id]);
        $this->assertEqualsCanonicalizing(
            [$root->id, $phones->id, $android->id, $pixel->id, $galaxy->id, $iphone->id],
            $rootDescendants
        );

        // Mid-level Phones expands to Phones, Android, Pixel, Galaxy, iPhone
        $phoneDescendants = $this->hierarchy->descendantsOf([$phones->id]);
        $this->assertEqualsCanonicalizing(
            [$phones->id, $android->id, $pixel->id, $galaxy->id, $iphone->id],
            $phoneDescendants
        );

        // Android expands to Android, Pixel, Galaxy
        $androidDescendants = $this->hierarchy->descendantsOf([$android->id]);
        $this->assertEqualsCanonicalizing(
            [$android->id, $pixel->id, $galaxy->id],
            $androidDescendants
        );

        // Leaf Pixel expands to only Pixel
        $pixelDescendants = $this->hierarchy->descendantsOf([$pixel->id]);
        $this->assertEqualsCanonicalizing([$pixel->id], $pixelDescendants);
    }

    #[Test]
    public function it_does_not_include_ancestors_or_siblings_in_descendants(): void
    {
        $electronics = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $phones = Category::create(['name' => 'Phones', 'slug' => 'phones', 'parent_id' => $electronics->id, 'is_active' => true]);
        $laptops = Category::create(['name' => 'Laptops', 'slug' => 'laptops', 'parent_id' => $electronics->id, 'is_active' => true]);
        $android = Category::create(['name' => 'Android', 'slug' => 'android', 'parent_id' => $phones->id, 'is_active' => true]);
        $gaming = Category::create(['name' => 'Gaming Laptops', 'slug' => 'gaming', 'parent_id' => $laptops->id, 'is_active' => true]);

        $phoneDescendants = $this->hierarchy->descendantsOf([$phones->id]);

        // Ancestor Electronics must NOT be present
        $this->assertNotContains($electronics->id, $phoneDescendants);
        // Sibling Laptops and its child Gaming must NOT be present
        $this->assertNotContains($laptops->id, $phoneDescendants);
        $this->assertNotContains($gaming->id, $phoneDescendants);
    }

    #[Test]
    public function it_handles_empty_and_unknown_ids_safely(): void
    {
        $this->assertSame([], $this->hierarchy->descendantsOf([]));
        $this->assertEqualsCanonicalizing([99999], $this->hierarchy->descendantsOf([99999]));
    }

    #[Test]
    public function it_resolves_ancestors_chain_upward(): void
    {
        $root = Category::create(['name' => 'Electronics', 'slug' => 'electronics', 'is_active' => true]);
        $phones = Category::create(['name' => 'Phones', 'slug' => 'phones', 'parent_id' => $root->id, 'is_active' => true]);
        $android = Category::create(['name' => 'Android', 'slug' => 'android', 'parent_id' => $phones->id, 'is_active' => true]);

        $ancestors = $this->hierarchy->ancestorsFor([$android->id, $root->id]);

        $this->assertSame([$android->id, $phones->id, $root->id], $ancestors[$android->id]);
        $this->assertSame([$root->id], $ancestors[$root->id]);
    }
}
