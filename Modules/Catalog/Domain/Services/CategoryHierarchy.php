<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Services;

use Modules\Catalog\Domain\Models\Category;

/**
 * Batch-safe traversal of the category tree, in both directions.
 *
 * Categories nest arbitrarily deep via `parent_id` with no path or nested-set
 * column, so naive traversal is a query per level per product. Instead the whole
 * `id => parent_id` map is read once — two integer columns over a table that is
 * small by nature — and every lookup after that is pure in-memory pointer walking.
 *
 * Both directions are needed, for different questions:
 *   - ancestorsFor()  answers "which category rules can reach this product?" and
 *                     feeds the pricing context (Android → Phones → Electronics).
 *   - descendantsOf() answers "which products does this category rule reach?" and
 *                     feeds the has_discount filter and campaign product lists.
 *
 * The map is memoized per instance, so one request resolves it at most once. The
 * service is registered as a scoped binding, so the memo never outlives a request
 * and an admin editing the tree is never served a stale hierarchy.
 */
class CategoryHierarchy
{
    /** @var array<int, int|null>|null id => parent_id */
    private ?array $parentMap = null;

    /** @var array<int, array<int, int>>|null parent_id => [child ids] */
    private ?array $childMap = null;

    /**
     * A category plus every ancestor above it, nearest first.
     *
     * @param  array<int, int|null>  $categoryIds
     * @return array<int, array<int, int>> keyed by the requested category id
     */
    public function ancestorsFor(array $categoryIds): array
    {
        $parents = $this->parentMap();
        $resolved = [];

        foreach ($categoryIds as $categoryId) {
            if ($categoryId === null || isset($resolved[$categoryId])) {
                continue;
            }

            $chain = [];
            $seen = [];
            $current = (int) $categoryId;

            // The `$seen` guard is not paranoia: a corrupted parent_id cycle would
            // otherwise spin here forever while holding a web request open.
            while ($current !== 0 && array_key_exists($current, $parents) && ! isset($seen[$current])) {
                $seen[$current] = true;
                $chain[] = $current;
                $parent = $parents[$current];

                if ($parent === null) {
                    break;
                }

                $current = (int) $parent;
            }

            $resolved[(int) $categoryId] = $chain;
        }

        return $resolved;
    }

    /**
     * The given categories plus everything beneath them, at any depth.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, int>
     */
    public function descendantsOf(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', array_filter($categoryIds))));

        if ($categoryIds === []) {
            return [];
        }

        $children = $this->childMap();
        $collected = [];
        $queue = $categoryIds;

        while ($queue !== []) {
            $id = array_pop($queue);

            if (isset($collected[$id])) {
                continue;
            }

            $collected[$id] = true;

            foreach ($children[$id] ?? [] as $childId) {
                if (! isset($collected[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return array_keys($collected);
    }

    /** @return array<int, int|null> */
    private function parentMap(): array
    {
        if ($this->parentMap === null) {
            $this->parentMap = Category::query()
                ->pluck('parent_id', 'id')
                ->map(static fn ($parentId) => $parentId === null ? null : (int) $parentId)
                ->all();
        }

        return $this->parentMap;
    }

    /** @return array<int, array<int, int>> */
    private function childMap(): array
    {
        if ($this->childMap === null) {
            $map = [];

            foreach ($this->parentMap() as $id => $parentId) {
                if ($parentId !== null) {
                    $map[$parentId][] = (int) $id;
                }
            }

            $this->childMap = $map;
        }

        return $this->childMap;
    }
}
